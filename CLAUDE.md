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
| `dev/logs/` | Mac | local logs, git-ignored |

## Checks before every commit

```bash
composer install && composer lint && composer test   # PHP unit tests
shellcheck install/install.sh dev/test-env.sh
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
- With a full-tunnel VPN active on the Mac the VM reaches the Mac and resolves names, but has no internet
  access. Its clock also falls behind, since it cannot sync.
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

## Current status (2026-11-05)

- Done: both panel pages load; the `VERIFY` items are checked on 3.3.2; dry-run lists the API calls on the
  form; real creation of website, DB user, DB and SFTP or FTP user works, and the generated logins work;
  the job reaches the worker, which passes the empty web root check. See [ROADMAP.md](ROADMAP.md).
- **Next immediate step**: finish a real WordPress install. It is untested: `wp core download` failed
  because the VM had no internet access (see the VPN note below).
- The VM has a remote user `wpmanager`, a client `testclient` and test sites `sito2`-`sito4`; its
  `config.php` has `dry_run => false`. To start clean: `./dev/test-env.sh reset`, then redo that setup.
- Then go through `docs/testing.md` (checklist) and write down results.

## Known open issues / roadmap

- Generated WP admin and SFTP passwords are never shown to the admin: needs a one-time reveal page.
- Multiserver: grant UPDATE on `wpm_site` to the `ispcsrvN` users (see `docs/architecture.md`).
- Admin-only for now; clients/resellers and quota checks later.
- Planned work, by milestone: see [ROADMAP.md](ROADMAP.md).
