"""
Deploy the plugin to a WordPress site over SSH.

Site specifics live in tools/deploy.local.json, which is gitignored, so this
script itself stays generic and safe to commit whatever the repository's
visibility becomes. Copy tools/deploy.local.example.json to get started.

Fully non-interactive: nothing is read from stdin, so it runs from a tool, a
`!` prefixed command, or a normal terminal alike.

Usage:
    python tools/deploy.py                  # dry run: connect and report only
    python tools/deploy.py --yes            # upload
    python tools/deploy.py --yes --prune    # upload, then delete files that
                                            # no longer exist locally

Always prefer --yes --prune for a real release. Uploading alone never removes
files deleted from the plugin, so a release that drops a class leaves it
orphaned on the server forever.

Secrets: the key passphrase is never stored in the config file. It comes from
an environment variable, or is discovered from your existing automation
scripts, and is held in memory only. Nothing sensitive is printed: the host is
masked and the passphrase never appears.

Exit codes: 0 success or clean dry run, non-zero on any failure.
"""

import glob
import json
import os
import posixpath
import re
import sys

try:
    import paramiko
except ImportError:
    sys.exit("paramiko is not installed. Run: pip install paramiko")

HERE = os.path.dirname(os.path.abspath(__file__))
REPO_ROOT = os.path.dirname(HERE)
CONFIG_PATH = os.path.join(HERE, "deploy.local.json")

SKIP_DIRS = {".git", "node_modules", "__pycache__"}
SKIP_EXTS = {".zip", ".log", ".swp"}

PASS_RE = re.compile(r"(?i)\b\w*(?:passphrase|password|key_pass|ssh_pass)\w*\s*=\s*[\"']([^\"']{3,})[\"']")
HOST_RE = re.compile(r"(?i)\b\w*(?:ssh_host|hostname|host|server)\w*\s*=\s*[\"']([^\"']{4,})[\"']")
USER_RE = re.compile(r"(?i)\b\w*(?:ssh_user|username|user)\w*\s*=\s*[\"']([^\"']{2,})[\"']")


def mask(value):
    """Show enough to recognise, not enough to reuse."""
    if not value:
        return "(none)"
    if len(value) <= 6:
        return value[0] + "***"
    return value[:3] + "***" + value[-3:]


def load_config():
    if not os.path.isfile(CONFIG_PATH):
        sys.exit(
            "Missing %s\n"
            "Copy tools/deploy.local.example.json to tools/deploy.local.json and\n"
            "fill in your site details. That file is gitignored on purpose." % CONFIG_PATH
        )
    try:
        with open(CONFIG_PATH, "r", encoding="utf-8") as handle:
            cfg = json.load(handle)
    except ValueError as exc:
        sys.exit("%s is not valid JSON: %s" % (CONFIG_PATH, exc))

    for required in ("wp_root", "ssh"):
        if required not in cfg:
            sys.exit("%s is missing the required \"%s\" key." % (CONFIG_PATH, required))
    if "key_path" not in cfg["ssh"]:
        sys.exit("%s is missing ssh.key_path." % CONFIG_PATH)

    cfg.setdefault("plugin_slug", "ai-internal-linking")
    cfg.setdefault("plugin_dir", os.path.join(REPO_ROOT, cfg["plugin_slug"]))
    cfg["ssh"].setdefault("port", 22)
    return cfg


def harvest_from_scripts(directory):
    """
    Scrape connection candidates from automation scripts you already have.

    This exists so the passphrase never has to be written into a config file.
    Values are returned in memory and never printed.
    """
    passphrases, hosts, users = [], [], []
    if not directory or not os.path.isdir(directory):
        return passphrases, hosts, users
    for path in sorted(glob.glob(os.path.join(directory, "*.py"))):
        try:
            with open(path, "r", encoding="utf-8", errors="replace") as handle:
                text = handle.read()
        except OSError:
            continue
        if "paramiko" not in text and "ssh" not in text.lower():
            continue
        for value in PASS_RE.findall(text):
            if value not in passphrases:
                passphrases.append(value)
        for value in HOST_RE.findall(text):
            if "/" in value or "\\" in value or " " in value:
                continue
            if "." in value and value not in hosts:
                hosts.append(value)
        for value in USER_RE.findall(text):
            if value.isalnum() and value not in users:
                users.append(value)
    return passphrases, hosts, users


def local_files(root):
    out = []
    for dirpath, dirnames, filenames in os.walk(root):
        dirnames[:] = [d for d in dirnames if d not in SKIP_DIRS]
        for name in filenames:
            if os.path.splitext(name)[1].lower() in SKIP_EXTS:
                continue
            full = os.path.join(dirpath, name)
            rel = os.path.relpath(full, root).replace(os.sep, "/")
            out.append((full, rel))
    return sorted(out, key=lambda p: p[1])


def run(client, command):
    stdin, stdout, stderr = client.exec_command(command)
    out = stdout.read().decode("utf-8", "replace").strip()
    err = stderr.read().decode("utf-8", "replace").strip()
    code = stdout.channel.recv_exit_status()
    return code, out, err


def ensure_remote_dir(sftp, path):
    current = ""
    for part in path.strip("/").split("/"):
        current = current + "/" + part
        try:
            sftp.stat(current)
        except IOError:
            sftp.mkdir(current)


def load_key(key_path, passphrases):
    """Try each candidate passphrase, plus no passphrase, until one loads."""
    for candidate in list(passphrases) + [None]:
        for loader in (paramiko.RSAKey, paramiko.Ed25519Key, paramiko.ECDSAKey):
            try:
                return loader.from_private_key_file(key_path, password=candidate)
            except Exception:
                continue
    return None


def connect(cfg, key, hosts, users):
    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    port = int(cfg["ssh"]["port"])
    last_error = None
    for host in hosts:
        for user in users:
            try:
                client.connect(hostname=host, port=port, username=user,
                               pkey=key, timeout=20)
                return client, host, user
            except Exception as exc:
                last_error = exc
    sys.exit("Could not connect to any candidate host. Last error: %s" % last_error)


def read_live_version(client, header_path):
    code, out, _ = run(
        client,
        "grep -m1 -oP '^\\s*\\*\\s*Version:\\s*\\K[0-9.]+' %s" % header_path,
    )
    return out or "unknown"


def prune(client, sftp, remote_plugin, expected):
    """Delete remote files that no longer exist locally, scoped to the plugin."""
    if not expected:
        print("Refusing to prune: the local file list is empty.")
        return
    code, listing, _ = run(client, "find %s -type f -printf '%%P\\n'" % remote_plugin)
    remote_rel = [line.strip() for line in listing.splitlines() if line.strip()]
    if not remote_rel:
        print("Refusing to prune: could not list remote files.")
        return
    orphans = [r for r in remote_rel if r not in expected]
    if not orphans:
        print("Prune: nothing stale, the server matches the local plugin exactly.")
        return
    for rel in orphans:
        try:
            sftp.remove(posixpath.join(remote_plugin, rel))
            print("Pruned: %s" % rel)
        except IOError as exc:
            print("Could not prune %s: %s" % (rel, exc))


def main():
    do_upload = "--yes" in sys.argv
    do_prune = "--prune" in sys.argv

    cfg = load_config()
    plugin_dir = cfg["plugin_dir"]
    key_path = cfg["ssh"]["key_path"]

    if not os.path.isdir(plugin_dir):
        sys.exit("Local plugin folder not found: %s" % plugin_dir)
    if not os.path.isfile(key_path):
        sys.exit("SSH key not found: %s" % key_path)

    # Passphrase, in order of preference, never from the config file itself.
    passphrases = []
    env_name = cfg["ssh"].get("passphrase_env")
    if env_name and os.environ.get(env_name):
        passphrases.append(os.environ[env_name])
    harvested_pass, harvested_hosts, harvested_users = harvest_from_scripts(
        cfg["ssh"].get("passphrase_from_scripts")
    )
    passphrases.extend(p for p in harvested_pass if p not in passphrases)

    hosts = [cfg["ssh"]["host"]] if cfg["ssh"].get("host") else list(harvested_hosts)
    users = [cfg["ssh"]["user"]] if cfg["ssh"].get("user") else list(harvested_users)
    if not hosts:
        sys.exit("No SSH host: set ssh.host in %s." % CONFIG_PATH)
    if not users:
        sys.exit("No SSH user: set ssh.user in %s." % CONFIG_PATH)

    key = load_key(key_path, passphrases)
    if key is None:
        sys.exit("Could not unlock %s with any available passphrase." % key_path)
    print("Private key unlocked.")

    client, host, user = connect(cfg, key, hosts, users)
    print("Connected to %s as %s" % (mask(host), user))

    remote_plugin = posixpath.join(cfg["wp_root"], "wp-content/plugins", cfg["plugin_slug"])
    header = posixpath.join(remote_plugin, cfg["plugin_slug"] + ".php")

    before = read_live_version(client, header)
    print("Currently live version: %s" % before)

    files = local_files(plugin_dir)
    print("Local files to upload: %d" % len(files))

    if not do_upload:
        print("\nDRY RUN. Nothing was uploaded. Re-run with --yes to deploy.")
        client.close()
        return 0

    sftp = client.open_sftp()
    ensure_remote_dir(sftp, remote_plugin)
    made = set()
    for full, rel in files:
        target = posixpath.join(remote_plugin, rel)
        parent = posixpath.dirname(target)
        if parent not in made:
            ensure_remote_dir(sftp, parent)
            made.add(parent)
        sftp.put(full, target)
    print("Uploaded %d files." % len(files))

    if do_prune:
        prune(client, sftp, remote_plugin, set(rel for _, rel in files))
    sftp.close()

    # Lint with the server's own PHP, which is what actually runs the site.
    code, lint, _ = run(
        client,
        "find %s -name '*.php' -print0 | xargs -0 -n1 php -l 2>&1 | grep -v '^No syntax errors' || true"
        % remote_plugin,
    )
    if lint:
        print("SERVER LINT FAILED:\n%s" % lint)
        print("Old files were overwritten. Roll back before using the site.")
        client.close()
        return 1
    print("Server lint clean.")

    after = read_live_version(client, header)
    print("Live version now: %s" % after)

    code, out, err = run(client, "cd %s && wp cache flush --allow-root 2>&1 || true" % cfg["wp_root"])
    print("Cache flush: %s" % (out or err or "no output"))

    client.close()
    print("\nDeployed %s -> %s." % (before, after))
    print("Open WP Admin once so any pending database upgrade runs.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
