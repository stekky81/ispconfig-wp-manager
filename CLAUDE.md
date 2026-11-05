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
| `server/plugins-available/wpmanager_plugin.inc.php` | nodes | copied into ISPConfig, queues jobs |
| `server/bin/wpm-worker.php` | nodes | cron, runs WP-CLI |
| `install/install.sh` | master + nodes | idempotent; `--role=master`, `--role=node`, `--uninstall` |
| `dev/test-env.sh` | Mac | Multipass test environment |
| `dev/logs/` | Mac | local logs, git-ignored |

## Checks before every commit

```bash
composer install && composer lint && composer test   # PHP unit tests (47 at the time of writing)
shellcheck install/install.sh dev/test-env.sh
```

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

## Current status (2026-11-05)

- Done: skeleton, 47 unit tests, CI, test VM, module installed and visible in the panel menu.
- **Next immediate step**: after the "copy instead of symlink" fix (commit f0fb549), run `sync`, re-run
  the installer, verify that *WordPress sites* and *New WordPress site* pages load. Check the Apache
  error log for the next problem (likely candidates below).
- Things still marked `VERIFY` in the code (written from ISPConfig 3.2 docs, now running on 3.3.2):
  tform/listform flow in `wpm_site_edit.php`, templates, `paging_tpl` path in the list definition
  (no `paging.tpl.htm` exists under `interface/web/sites/templates/`), Remote API parameters in
  `lib/SiteProvisioner.php`.
- To test the create-site flow end to end: create a remote user in the panel (System → Remote Users),
  put it in `/opt/ispconfig-wp-manager/config.php` in the VM (keep `dry_run => true` first, then false),
  create a client, then a WordPress site with a `*.ispc.test` domain; set `ssl_letsencrypt => 'n'`.
  Note: `sync` keeps the VM's `config.php`.
- Then go through `docs/testing.md` (checklist) and write down results.

## Known open issues / roadmap

- Generated WP admin and SFTP passwords are never shown to the admin: needs a one-time reveal page.
- Multiserver: grant UPDATE on `wpm_site` to the `ispcsrvN` users (see `docs/architecture.md`).
- Admin-only for now; clients/resellers and quota checks later.
- Planned work, by milestone: see [ROADMAP.md](ROADMAP.md).
