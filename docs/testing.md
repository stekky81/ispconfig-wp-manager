# Test plan

Because this module creates and changes live websites, **nothing is merged or released without
passing both the automated tests and the manual test plan below.**

## 1. Automated tests (no server needed)

```bash
composer install
composer test
```

They cover the shared classes in `lib/`: input validation, encryption of job secrets, WP-CLI command
building (escaping, run-as-user), path guard and empty-directory check, configuration merging.
Every bug found in manual testing must get a regression test here first.

## 2. Test environment

Never test on the production nodes. Use two disposable VMs that mirror production.

### Quick start on macOS: Multipass

```bash
brew install --cask multipass        # macOS needs Multipass 1.17.0 or later
UBUNTU=24.04 WEB=apache ./dev/test-env.sh create   # same Ubuntu and web server as production
./dev/test-env.sh info                             # panel URL and IP
./dev/test-env.sh sync                             # copy the current code into the VM
./dev/test-env.sh reset                            # back to the clean snapshot (then syncs again)
./dev/test-env.sh destroy                          # delete everything
```

The project is copied into the VM at `/opt/ispconfig-wp-manager`: run `sync` after every change.
(A live mount is not possible: the ISPConfig autoinstaller removes snapd, which Multipass mounts need.)
The VM's own `config.php` is kept across syncs.

What it does **not** touch on the Mac:

- The VMs have their own IP address: a local Apache/PHP on the Mac keeps working and there are
  no port conflicts (nothing is forwarded to `localhost`).
- No system files are changed. The only manual step is adding test domains to `/etc/hosts`;
  use a dedicated suffix (e.g. `sito1.ispc.test`) so they never clash with local virtual hosts.
- The VMs cannot write to the Mac at all: code goes one way, from the Mac into the VM.

Full removal: `./dev/test-env.sh destroy` deletes the VMs and frees their disk space;
`brew uninstall --cask multipass` removes Multipass; finally remove the test lines from `/etc/hosts`.

### Generic setup

Any VM tool works (UTM, Parallels, VMware Fusion, Proxmox, Incus):

- **VM A**: ISPConfig master + web node (same OS and ISPConfig version as production)
- **VM B**: ISPConfig slave web node (to test multiserver)
- Take a snapshot of both VMs **before every test run** and revert afterwards.

## 3. Manual test checklist

Ticked items passed on ISPConfig 3.3.2 in the Multipass VM (master node only).

Run each case first with `dry_run = true` (check the calls listed on the form), then with `dry_run = false`.

### Install

- [x] Installer runs twice in a row without errors (idempotent)
- [ ] Installer after an ISPConfig update restores the module
- [x] `--uninstall` removes the module and leaves sites, tables and keys alone; the installer brings it back
- [ ] Module visible only to users who have it enabled

### New site — happy path

- [x] Site on master node: website, DB user, DB (linked to the site), SFTP user created
- [x] Same with FTP access: FTP user created instead of the SFTP user, login works
- [ ] Site on slave node (VM B)
- [x] WordPress reachable, admin login works, `wp-config.php` owned by `webN` with mode 0640 or stricter
- [x] Secrets cleared from `wpm_site` after install (DB password) and after the reveal (the other two)
- [x] Passwords shown once on the site detail page, and they work
- [ ] Defaults applied (PHP version, SSL, plugins)

### New site — failure cases

- [x] Domain already exists in ISPConfig → clear error, nothing created
- [x] Database, database user or SFTP/FTP user name already taken → clear error, nothing created
- [x] Two long domains with the same beginning → different names, both created
- [ ] Client over quota → clear error, nothing created
- [x] API fails halfway (e.g. missing permission) → clear error, created objects rolled back
- [x] Wrong `database_server_id` → clear error, nothing created
- [x] `web/` not empty → worker refuses, status `error`, no files touched
- [x] WP-CLI missing on node → status `error` with message
- [ ] Node offline for 10 minutes → job runs when it is back
- [x] Two sites created at the same moment → both installed, no mix-up

### Backups and updates

- [x] Backup and restore from the panel: files and database back as they were
- [x] The last 3 backups are kept
- [x] Backup without uploads: a restore keeps files uploaded later; a full backup restores uploads
- [x] Plugin, theme and core updates from the panel
- [x] An update that breaks the site is rolled back
- [x] A site already broken is not updated
- [x] Automatic update runs by itself; the switch of the site and the global one stop it
- [x] After a rollback the same versions are not tried again
- [x] A major release is never automatic; its own button updates it

### Clone

- [x] Long domain to short domain, to another client: site works, source unchanged
- [x] Serialized arrays and objects, JSON, URL encoding, paths and tables without prefix are replaced
- [x] Email addresses, similar domains and `guid` stay as they are
- [x] A value in base64 is reported
- [x] Custom table prefix is kept
- [x] "Discourage search engines" is on in the clone, not in the source
- [ ] Objects of plugin classes, with a real plugin (Elementor, WooCommerce)
- [ ] A large and busy site

### WP-Cron

- [x] To real cron and back: job and crontab line appear and go, `wp-config.php` ends up identical

### Security

- [x] Panel user cannot change `config.php`; the worker refuses one that others own or can write
- [x] A client with the module enabled is refused by every page
- [x] A forged POST without a CSRF token creates nothing, also if the form was never opened
- [x] A must-use plugin in the daily check cannot run programs or leave the site folder
- [x] FFI and `putenv(LD_PRELOAD)` with `mail()` or `error_log()` do not get out of the sandbox
- [x] Core, plugin and theme updates work in the sandbox, automatic and from the panel
- [x] A must-use plugin that dies in the sandbox makes the update roll back, with the reason in the message
- [x] Backup, restore, rollback and WP-Cron switch on a site with hostile `wp-config.php`, `wp-cli.yml` and must-use plugin:
      all of it runs in the sandbox
- [x] A dump with `\!` is refused and starts nothing
- [x] The database password does not appear in `ps` or in the log during a backup
- [x] `DB_HOST` with a socket and with a port
- [x] Endless output is stopped and the worker carries on
- [x] A hostile `home` option and a `wp-cli.yml` in the source do not reach the clone

### Regression on existing sites

Run `dev/regression.sh snapshot` in the VM before the test and `dev/regression.sh compare` after it.

- [x] Before/after checksum of every existing site's `web/` folder: unchanged (master VM only)
- [x] Existing databases unchanged (master VM only)
- [x] Existing vhosts unchanged (master VM only)

## 4. Pilot on production

Only after all checks pass: enable the module for one admin user, create one throwaway site
on each production node, verify, delete it. Then use it for real sites.
