# CLAUDE.md — ispconfig-wp-manager

Notes for Claude Code working in this repository. Talk to the maintainer (Stefano) in **Italian**;
write code, comments, docs and commit messages in **English**.

## What this is

An ISPConfig 3 module to create, clone and keep WordPress sites updated across ISPConfig nodes
(an open source "WP Toolkit" for ISPConfig). **Pre-release**: nothing here has run on production.
Read `README.md` and `docs/architecture.md` first.

## Hard rules

- **Never touch the production ISPConfig nodes.** All testing happens in the local Multipass VM.
- Do not change anything on the Mac outside this folder: the Mac has its own Apache, PHP (Homebrew)
  and Docker that must keep working. Never edit system files, never `brew` install/uninstall without asking.
- Safety rules in `docs/architecture.md` are not negotiable: dry-run stays the default, no shell strings
  (argument arrays only), no secrets in arguments or logs, never touch a non-empty web root,
  WP-CLI only as the site user.
- Never break what already exists: any change that can touch sites, databases or the installer is checked in
  the VM with `dev/regression.sh snapshot` before and `compare` after, around a real run.
- Every bug found in the VM gets a regression test in `tests/` when the logic lives in `lib/`.
- Each PHP file starts with `// SPDX-License-Identifier: BSD-3-Clause`.
- When a change completes an item in `ROADMAP.md`, tick it in the same commit.
- `git push` is done by Stefano unless he says otherwise.

## Layout

| Path | Runs on | Notes |
|------|---------|-------|
| `lib/` | master + nodes | framework-free, unit tested (`tests/`) |
| `interface/web/wpmanager/` | panel | ISPConfig module, **copied** into `/usr/local/ispconfig/interface/web/` by the installer |
| `server/mods-available/wpmanager_module.inc.php` | nodes | copied into ISPConfig, turns `wpm_site` datalog records into events |
| `server/plugins-available/wpmanager_plugin.inc.php` | nodes | copied into ISPConfig, queues jobs |
| `server/bin/wpm-worker.php` | nodes | cron, runs WP-CLI |
| `install/install.sh` | master + nodes | idempotent; `--role=master`, `--role=node`, `--uninstall` |
| `dev/test-env.sh` | Mac | Multipass test environment |
| `dev/regression.sh` | VM | checks that existing sites did not change |
| `dev/logs/` | Mac | local logs, git-ignored |

## Checks before every commit

```bash
composer install && composer lint && composer test   # PHP unit tests
shellcheck install/install.sh dev/test-env.sh dev/regression.sh
```

`shellcheck` is not on the Mac: run it in the VM after `sync`, with `sudo` (`dev/` is 0700 there).

## Test environment (Mac, Apple Silicon, macOS 14)

Multipass **1.17.0 or later** is required (1.16.x ships a qemu-img that segfaults).

```bash
./dev/test-env.sh info      # VM IP and panel URL (https://<ip>:8080, user admin)
./dev/test-env.sh sync      # copy this folder into the VM at /opt/ispconfig-wp-manager (run after every change)
./dev/test-env.sh reset     # restore the VM to the clean snapshot, then sync
./dev/test-env.sh shell     # root-capable shell in the VM
multipass exec ispc-master -- sudo <command>   # run anything in the VM
```

- VM `ispc-master`: Ubuntu 24.04 ARM64, ISPConfig **3.3.2** (Apache, no mail/DNS/firewall/quota),
  FQDN `ispc-master.ispc.test`, WP-CLI in `/usr/local/bin/wp`. Snapshot `clean` = ISPConfig installed,
  our module NOT installed.
- The ISPConfig admin password is in the VM: `sudo grep -a "admin password" /root/ispconfig-install.log`.
  Never write passwords into the repository.
- Install our module in the VM:
  `multipass exec ispc-master -- sudo /opt/ispconfig-wp-manager/install/install.sh --role=master --role=node`
- Panel PHP errors: `multipass exec ispc-master -- sudo tail -50 /var/log/apache2/error.log`
- Network: the home connection resets TLS connections that offer the post-quantum key share
  (`openssl s_client -groups X25519MLKEM768`) to some hosts, including `api.anthropic.com` and
  `api.wordpress.org`, so Claude Code drops with ECONNRESET. The Mac is not the cause: the same test passes on
  a phone hotspot. With the Tunnelblick VPN Claude Code works but the VM has no internet access, so work on
  the phone hotspot, without VPN.
- On the hotspot DNS through the Mac is unreliable and WP-CLI times out at 10 seconds: the VM uses public DNS,
  from `/etc/systemd/resolved.conf.d/90-wpm-test.conf` (`DNS=1.1.1.1 8.8.8.8`, `Domains=~.`).
- A test that attacks something must first check that the deploy worked: one ran on old code and damaged
  `config.php`. Sessions also expire, so a blank answer from the panel proves nothing.
- A manual `wpm-worker.php` exits silently while the cron worker holds the lock: to test it, wait for cron.
- `multipass exec` sometimes never returns although the command finished in the VM, so wrap it in `timeout`.
  The cause is unknown: passing the `sync` script as an argument instead of on stdin did not help.
- Do not change the VM clock from `multipass exec` with `date -s`: the client can hang. Kill it if it does.
- Do not run `destroy`/`delete` while a `create` or `mount` is running: it hung multipassd once.
  If multipassd hangs: `sudo pkill -9 -f "com.canonical.multipass/bin/"`, then reload the launchd plist.

## Lessons learned on the real ISPConfig (do not regress)

1. The `ispconfig` DB user cannot CREATE tables: the installer uses the admin account from
   `server/lib/mysql_clientdb.conf` and grants DML on `wpm_site` to the panel user.
2. Top menu titles are looked up in ISPConfig's global language file: use plain titles.
3. The panel runs as `ispconfig`: `/etc/ispconfig-wp-manager` must be `0710 root:ispconfig`.
4. Modules must be **copied**, not symlinked (relative `../../lib/` includes break).
5. `/usr/local/ispconfig` is root-only: check it with `sudo`.
6. The autoinstaller removes snapd (no `multipass mount`), needs a TTY, and an FQDN that survives
   cloud-init's `/etc/hosts` regeneration — all handled in `dev/test-env.sh`.
7. The panel's opcache rechecks files every 2 seconds: wait a few seconds after the installer,
   or a test may still run the previous code.
8. The panel discards `$app->log()` messages below `log_priority` (2 = errors only).
9. `server.sh` ignores datalog records of tables no module registered: a plugin alone gets no events.
10. A new web root contains `standard_index.html`, not `index.html`.
11. Ubuntu cloud images set `PasswordAuthentication no`: to test SFTP logins in the VM, add a file in
    `/etc/ssh/sshd_config.d/` with a name sorting before `60-cloudimg-settings.conf`.
12. `$app->tform->getCurrentTab()` is empty if the form was not opened in that session, and `getSQL()` then
    returns at once, skipping CSRF and validators: always pass the tab name.
13. The first full load of WordPress after an install rewrites its `cron` option, as a first visit would.
14. `disable_functions` is not enough in the CLI: FFI is on by default there (`ffi.enable=preload`), and
    `putenv(LD_PRELOAD)` with `mail()` or `error_log()` runs a library of the site. `wp db export` and `db import` load
    `wp-config.php`, but not must-use plugins, and any WP-CLI command runs a `require:` of `wp-cli.yml`.
15. The `mysql` client obeys `\!` in a file it reads, unless started with `--binary-mode`.
16. A site's table prefix is not always `wp_`: read it from `wp-config.php` in a test script.


## Current status (2026-11-05)

- Done: the create flow works end to end in the VM, with SFTP or FTP and per-site options; site detail page
  with one-time passwords; spaced retries; detection of existing sites; settings page; update overview;
  backups, updates with health check and automatic rollback, automatic updates; clone on the same node;
  WP-Cron switch; `--uninstall`. See [ROADMAP.md](ROADMAP.md) and the ticked items
  in `docs/testing.md`.
- **Next**: staging needs a decision first. Multiserver and clone across nodes are set aside for now.
  Security review done: see `docs/security.md`, including what remains open.
- Automatic updates are on by default and start as soon as `dry_run` is off: turn them off in the settings
  before a read-only look at a real node.
- The VM has a remote user `wpmanager`, a client `testclient` and test sites; its `config.php` has
  `dry_run => false`, password login in sshd and public DNS. `./dev/test-env.sh reset` drops all of this.

## Known open issues / roadmap

- Multiserver: grant INSERT and UPDATE on `wpm_site` to the `ispcsrvN` users (see `docs/architecture.md`).
- Admin-only for now; clients/resellers and quota checks later.
- Planned work, by milestone: see [ROADMAP.md](ROADMAP.md).
