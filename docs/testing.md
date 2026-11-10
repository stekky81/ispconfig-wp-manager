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
- [x] After an ISPConfig update (3.3.2p1 over itself, with services reconfigured) the module, its files, links,
      grants, key and cron are unchanged and the sites too; our installer run again changes nothing
- [x] `--uninstall` removes the module and leaves sites, tables and keys alone; the installer brings it back
- [x] Module visible only to users who have it enabled: without it the menu has no entry and every page sends the
      user away; with it a client still gets "Only administrators" on every page

### New site — happy path

- [x] Site on master node: website, DB user, DB (linked to the site), SFTP user created
- [x] Same with FTP access: FTP user created instead of the SFTP user, login works
- [ ] Site on slave node (VM B)
- [x] WordPress reachable, admin login works, `wp-config.php` owned by `webN` with mode 0640 or stricter
- [x] Secrets cleared from `wpm_site` after install (DB password) and after the reveal (the other two)
- [x] Passwords shown once on the site detail page, and they work
- [x] Passwords nobody looked at are deleted 7 days after the site is ready, and the page says they expired;
      after a reveal it says they were already shown
- [x] Defaults saved on the settings page reach the form and the site: PHP version, disk quota, SSL, `www`, language,
      plugins, theme (a localized download leaves `WPLANG` empty: the package itself sets the language)

### New site — failure cases

- [x] Domain already exists in ISPConfig → clear error, nothing created
- [x] Database, database user or SFTP/FTP user name already taken → clear error, nothing created
- [x] Two long domains with the same beginning → different names, both created
- [x] Client over quota: an admin is not limited, as in the ISPConfig panel, which checks only clients who log in
      themselves, and the Remote API checks nothing. Limits come with client access (see ROADMAP)
- [x] API fails halfway (e.g. missing permission) → clear error, created objects rolled back
- [x] Wrong `database_server_id` → clear error, nothing created
- [x] `web/` not empty → worker refuses, status `error`, no files touched
- [x] WP-CLI missing on node → status `error` with message
- [x] Node offline for 10 minutes (cron stopped) → the site waits in `queued`, nothing on disk; installed 95 s after
      the node is back
- [x] A theme or plugin that installs fine but breaks the site (the wordpress.org theme `default` under PHP 8) →
      status `installed` with a warning that says what the check saw
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

### Staging

- [x] Created from the production page, at `staging.<domain>`, for the same client, custom table prefix kept
- [x] Its PHP pool has `sendmail_path = /bin/true`, production keeps its own
- [x] `X-Robots-Tag: noindex, nofollow` and the robots meta tag on the staging only
- [x] Each page links to the other; a second staging and a staging of a staging are refused
- [x] Update from production: a wrong domain is refused; staging files and database replaced, its address,
      `wp-config.php` and "Discourage search engines" kept, production untouched
- [x] Push to production: warning with the newer versions, a wrong domain is refused, maintenance mode (503) during
      the copy, address, `wp-config.php` and search engine setting of production kept, no staging address left
- [x] A push that breaks production is rolled back, and the message says what the check saw
- [x] Production restored to the backup before the first push matches the regression snapshot

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
- [x] A command of a site that ignores SIGTERM is killed after the timeout, with what it started, and the lock
      of the worker is free again
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
