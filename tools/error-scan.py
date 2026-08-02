"""
Analyse PHP fatal errors in a WordPress site's error logs. Strictly read-only.

Aggregation happens ON THE SERVER, so a multi-megabyte log is summarised
remotely and only the summary crosses the wire.

Answers: how many fatals, when did they happen, are they still happening, and
which plugin or theme is responsible. Attribution is by file path, so
"which code is at fault" is a fact from the log rather than a guess.

Usage:
    python tools/error-scan.py              # summary
    python tools/error-scan.py --samples    # also show a few raw lines per signature

Uses the same tools/deploy.local.json config as deploy.py.
"""

import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)

import deploy  # noqa: E402  shared config + connection handling

TOP_N = 15


def human(num_bytes):
    try:
        n = float(num_bytes)
    except (TypeError, ValueError):
        return str(num_bytes)
    for unit in ("B", "KB", "MB", "GB"):
        if n < 1024 or unit == "GB":
            return "%.1f %s" % (n, unit)
        n /= 1024


def section(title):
    print("")
    print(title)
    print("-" * len(title))


def find_logs(client, wp_root):
    """Locate the usual log files without walking the whole account."""
    code, out, _ = deploy.run(
        client,
        "find %s -maxdepth 3 \\( -name 'error_log' -o -name 'debug.log' \\) "
        "-size +0 2>/dev/null | head -10" % wp_root,
    )
    return [line.strip() for line in out.splitlines() if line.strip()]


def analyse(client, log, show_samples):
    section(log)

    code, out, _ = deploy.run(
        client, "stat -c '%%s|%%y' '%s' 2>/dev/null" % log
    )
    if "|" in out:
        size, modified = out.strip().split("|", 1)
        print("size            : %s" % human(size))
        print("last written    : %s" % modified.strip()[:19])

    code, out, _ = deploy.run(client, "wc -l < '%s'" % log)
    print("total lines     : %s" % out.strip())

    code, out, _ = deploy.run(client, "grep -c 'PHP Fatal' '%s' || true" % log)
    fatal_count = out.strip() or "0"
    print("PHP Fatal lines : %s" % fatal_count)

    if fatal_count in ("0", ""):
        print("no fatals in this file")
        return

    code, out, _ = deploy.run(
        client, "grep -m1 'PHP Fatal' '%s' | cut -c1-60" % log
    )
    print("first fatal     : %s" % out.strip())
    code, out, _ = deploy.run(
        client, "grep 'PHP Fatal' '%s' | tail -1 | cut -c1-60" % log
    )
    print("last fatal      : %s" % out.strip())

    # Are they still happening, or is this a historical pile?
    print("")
    print("fatals per month:")
    code, out, _ = deploy.run(
        client,
        "grep 'PHP Fatal' '%s' | grep -oE '^\\[[0-9]{2}-[A-Za-z]{3}-[0-9]{4}' "
        "| sed 's/^\\[[0-9]*-//' | sort | uniq -c | sort -k2" % log,
    )
    for line in out.splitlines():
        if line.strip():
            print("   %s" % line.strip())

    # Which plugin or theme owns the failing file.
    print("")
    print("top culprits by path:")
    code, out, _ = deploy.run(
        client,
        "grep 'PHP Fatal' '%s' "
        "| grep -oE 'wp-content/(plugins|themes|mu-plugins)/[^/ ]+' "
        "| sort | uniq -c | sort -rn | head -%d" % (log, TOP_N),
    )
    rows = [l.strip() for l in out.splitlines() if l.strip()]
    if rows:
        for line in rows:
            print("   %s" % line)
    else:
        print("   (no wp-content path in any fatal; likely core or PHP level)")

    # Distinct failure signatures, with digits collapsed so line numbers and
    # ids do not split one recurring error into hundreds of variants.
    print("")
    print("top distinct messages:")
    code, out, _ = deploy.run(
        client,
        "grep 'PHP Fatal' '%s' | sed -E 's/^\\[[^]]*\\] //' "
        "| sed -E 's/[0-9]+/N/g' | cut -c1-150 "
        "| sort | uniq -c | sort -rn | head -%d" % (log, TOP_N),
    )
    for line in out.splitlines():
        if line.strip():
            print("   %s" % line.strip())

    # Does our own plugin appear in any fatal at all?
    print("")
    code, out, _ = deploy.run(
        client, "grep 'PHP Fatal' '%s' | grep -c -i 'ailinking' || true" % log
    )
    mine = out.strip() or "0"
    print("fatals mentioning ai-internal-linking: %s" % mine)

    if show_samples:
        print("")
        print("most recent fatals (raw):")
        code, out, _ = deploy.run(
            client, "grep 'PHP Fatal' '%s' | tail -5" % log
        )
        for line in out.splitlines():
            if line.strip():
                print("   %s" % line.strip()[:240])


def main():
    show_samples = "--samples" in sys.argv
    cfg = deploy.load_config()
    client = deploy.open_connection(cfg)

    logs = find_logs(client, cfg["wp_root"])
    if not logs:
        print("No error_log or debug.log found under %s" % cfg["wp_root"])
        client.close()
        return 0

    print("Found %d log file(s)." % len(logs))
    for log in logs:
        analyse(client, log, show_samples)

    client.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
