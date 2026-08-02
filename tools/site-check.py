"""
Health check for the deployed plugin. Read-only by default.

Answers the questions you actually care about after a deploy: did the database
migration run, is the schema what this version expects, is anything erroring,
and is the data still there. Uses the same tools/deploy.local.json config and
the same credential handling as deploy.py.

Usage:
    python tools/site-check.py                 # read-only diagnosis
    python tools/site-check.py --run-upgrade   # also trigger the pending
                                               # database migration headlessly

The migration normally runs on the first WP Admin page load. --run-upgrade
calls Schema::maybe_upgrade() through wp-cli instead, which is the same code
path, for when you would rather not open a browser.

Nothing here writes to post content. --run-upgrade can create or drop plugin
tables, which is exactly what a version upgrade does.
"""

import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)

import deploy  # noqa: E402  same folder, shared config + connection handling

EXPECTED_TABLES = [
    "index", "link_graph", "suggestions", "ledger", "tfidf", "jobs",
    "provider_keys", "spend_log", "keywords", "keyword_map",
]
# Tables that must NOT exist any more, and the version that retired each.
RETIRED_TABLES = {
    "embeddings": "0.14.0",
    "clusters": "0.11.0",
    "cluster_members": "0.11.0",
}


def wp(client, cfg, command):
    """Run a wp-cli command in the WordPress root."""
    return deploy.run(
        client, "cd %s && wp %s --allow-root 2>&1" % (cfg["wp_root"], command)
    )


def section(title):
    print("")
    print(title)
    print("-" * len(title))


def main():
    run_upgrade = "--run-upgrade" in sys.argv
    cfg = deploy.load_config()
    client = deploy.open_connection(cfg)
    slug = cfg["plugin_slug"]

    problems = []
    warnings = []

    # --- Plugin state -------------------------------------------------------
    section("Plugin")
    code, out, _ = wp(client, cfg, "plugin get %s --field=status" % slug)
    status = out.strip().splitlines()[-1] if out.strip() else "unknown"
    print("status            : %s" % status)
    if status != "active":
        problems.append("Plugin is not active (status: %s)" % status)

    code, out, _ = wp(client, cfg, "plugin get %s --field=version" % slug)
    version = out.strip().splitlines()[-1] if out.strip() else "unknown"
    print("version           : %s" % version)

    # --- Migration state ----------------------------------------------------
    section("Database migration")
    code, stored_db, _ = wp(client, cfg, "option get ailinking_db_version")
    stored_db = stored_db.strip().splitlines()[-1] if stored_db.strip() else "(not set)"
    print("stored db_version : %s" % stored_db)

    code, expected_db, _ = deploy.run(
        client,
        "grep -m1 -oP \"AILINKING_DB_VERSION',\\s*'\\K[0-9.]+\" %s"
        % (cfg["wp_root"] + "/wp-content/plugins/" + slug + "/" + slug + ".php"),
    )
    expected_db = expected_db.strip() or "unknown"
    print("code expects      : %s" % expected_db)

    pending = stored_db != expected_db
    if pending:
        warnings.append(
            "Migration pending: stored %s, code expects %s. Open WP Admin once, "
            "or re-run with --run-upgrade." % (stored_db, expected_db)
        )

    if run_upgrade and pending:
        print("running migration ...")
        code, out, _ = wp(
            client, cfg,
            "eval '\\AILinking\\Install\\Schema::maybe_upgrade(); echo \"done\";'"
        )
        print("  %s" % out.strip().replace("\n", "\n  "))
        code, stored_db, _ = wp(client, cfg, "option get ailinking_db_version")
        stored_db = stored_db.strip().splitlines()[-1] if stored_db.strip() else "(not set)"
        print("stored db_version : %s (after)" % stored_db)
        if stored_db == expected_db:
            warnings = [w for w in warnings if not w.startswith("Migration pending")]

    # --- Schema -------------------------------------------------------------
    section("Tables")
    code, prefix, _ = wp(client, cfg, "db prefix")
    prefix = prefix.strip().splitlines()[-1] if prefix.strip() else ""
    code, out, _ = wp(client, cfg, "db query \"SHOW TABLES LIKE '%sailinking\\_%%'\" --skip-column-names" % prefix)
    present = set()
    for line in out.splitlines():
        line = line.strip()
        if line.startswith(prefix + "ailinking_"):
            present.add(line[len(prefix + "ailinking_"):])

    for name in EXPECTED_TABLES:
        mark = "ok " if name in present else "MISSING"
        print("  %-16s %s" % (name, mark))
        if name not in present:
            problems.append("Expected table missing: %sailinking_%s" % (prefix, name))

    for name, retired_in in sorted(RETIRED_TABLES.items()):
        if name in present:
            print("  %-16s STILL PRESENT (retired in %s)" % (name, retired_in))
            problems.append(
                "Retired table %sailinking_%s still exists (should have been dropped in %s)"
                % (prefix, name, retired_in)
            )
        else:
            print("  %-16s correctly absent (retired in %s)" % (name, retired_in))

    unexpected = present - set(EXPECTED_TABLES) - set(RETIRED_TABLES)
    for name in sorted(unexpected):
        print("  %-16s unexpected" % name)
        warnings.append("Unexpected table: %sailinking_%s" % (prefix, name))

    # --- Data ---------------------------------------------------------------
    section("Data")
    for name, label in (
        ("index", "indexed pages"),
        ("suggestions", "suggestions"),
        ("link_graph", "link graph rows"),
        ("keywords", "keywords"),
        ("spend_log", "AI calls logged"),
    ):
        if name not in present:
            continue
        code, out, _ = wp(
            client, cfg,
            "db query \"SELECT COUNT(*) FROM %sailinking_%s\" --skip-column-names" % (prefix, name)
        )
        count = out.strip().splitlines()[-1] if out.strip() else "?"
        print("  %-16s %s" % (label, count))

    # --- Errors -------------------------------------------------------------
    section("Errors")
    code, out, _ = deploy.run(
        client,
        "find %s -maxdepth 2 -name 'error_log' -o -maxdepth 2 -name 'debug.log' 2>/dev/null | head -5"
        % cfg["wp_root"],
    )
    logs = [l.strip() for l in out.splitlines() if l.strip()]
    if not logs:
        print("no error_log or debug.log found (often means no errors logged)")
    for log in logs:
        code, out, _ = deploy.run(
            client,
            "grep -i -E 'ailinking|AILinking' %s 2>/dev/null | tail -15" % log,
        )
        hits = [l for l in out.splitlines() if l.strip()]
        print("%s: %d plugin-related line(s)" % (log, len(hits)))
        for line in hits[-10:]:
            print("   %s" % line[:220])
        if hits:
            warnings.append("%d plugin-related line(s) in %s" % (len(hits), log))

        code, out, _ = deploy.run(
            client, "grep -c -i 'PHP Fatal' %s 2>/dev/null || echo 0" % log
        )
        fatal = out.strip().splitlines()[-1] if out.strip() else "0"
        print("%s: %s PHP Fatal line(s) total (all plugins)" % (log, fatal))

    # A load-time smoke test: if the plugin fatals or emits notices on boot,
    # wp-cli surfaces it here even when nothing was written to a log.
    section("Load smoke test")
    code, out, _ = wp(client, cfg, "eval 'echo defined(\"AILINKING_VERSION\") ? AILINKING_VERSION : \"NOT LOADED\";'")
    clean = out.strip()
    print("AILINKING_VERSION : %s" % (clean.splitlines()[-1] if clean else "(no output)"))
    noise = [l for l in clean.splitlines()[:-1] if l.strip()]
    if noise:
        print("unexpected output while loading WordPress:")
        for line in noise[:10]:
            print("   %s" % line[:220])
        problems.append("Plugin emits output/notices on load")

    # --- Verdict ------------------------------------------------------------
    section("Verdict")
    if not problems and not warnings:
        print("All checks passed.")
    for p in problems:
        print("PROBLEM : %s" % p)
    for w in warnings:
        print("WARNING : %s" % w)

    client.close()
    return 1 if problems else 0


if __name__ == "__main__":
    sys.exit(main())
