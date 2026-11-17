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

### Integration tests (real users, MariaDB, WP-CLI, WordPress; no ISPConfig)

`tests/Integration` runs the commands of `lib/` for real: as the site users through `sudo`, on a real WordPress, with
MariaDB: backup and restore, clone with serialized data, maintenance mode across a restore, debug mode, hardening,
permissions, temporary login, password reset, plugins, the daily check and the transfer store of the module. The CI
runs them on every push (job `integration`: MariaDB 11.4 as a service container, PHP 8.3, WP-CLI 2.12.0). On a machine
where creating two users is fine, as root:

```bash
dev/integration.sh setup        # users web901/web902, /var/www/clients-it, databases, WordPress; /etc/wpstation-it.env
set -a; . /etc/wpstation-it.env; set +a; vendor/bin/phpunit --testsuite integration
dev/integration.sh teardown
```

`MYSQL_HOST` and `MYSQL_PWD` tell the script how to reach MariaDB as root; `WPSTATION_IT_DB_HOST` is the host the sites use.

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

The project is copied into the VM at `/opt/ispconfig-wpstation`: run `sync` after every change.
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
- [x] Site on slave node (VM B, joined as server 2): created from the panel of the master, website and database on
      server 2, installed by the worker of the node, record updated on the master
- [x] On the slave node: daily check, backup, plugin update, clone and staging with an update from production; a
      WordPress site of the node not in the list is detected and added on the master
- [x] A node can update only the status columns of `wpstation_site` on the master: folder, user, server and automatic update
      switch are refused by MariaDB; the master installer runs twice without warnings
- [x] A row changed behind the back of the panel (another user) or added for a website already in the list is refused by
      the worker, logged, and does not stop the other sites
- [x] Install and clone in the sandbox, on the master and on the node
- [x] Clone to another node, both ways, through the master database; a site with a dump of 82 MB and 30,000 posts in
      327 s, at most 16 pieces waiting in the master database, none left at the end
- [x] A transfer request the panel did not make is refused by the source node, which sends nothing
- [x] A source node that claimed a transfer and stopped (full disk): the target gives up after 10 minutes and the
      next attempt works

### Emails

- [x] Settings saved from the panel, an invalid address refused, the other settings kept
- [x] Site ready (no password) from the master and, with warnings, from the node; the failed backup sent at once
- [x] One weekly summary for both nodes: problems, events, updates available
- [x] WordPress reachable, admin login works, `wp-config.php` owned by `webN` with mode 0640 or stricter
- [x] Secrets cleared from `wpstation_site` after install (DB password) and after the reveal (the other two)
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
- [x] A plugin that does not exist on wordpress.org → status `installed` with a warning that names the plugin, the
      other plugins installed
- [x] The database refuses the first attempt after WordPress was downloaded → "Installation of WordPress failed" with
      the error of WordPress; the retry runs over the files of that attempt and installs the site
- [x] Every failure names its step and, when it is known, the cause in plain words, with the output as details

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

### Who updates a site

- [x] After an upgrade every installed site gets its state within a few minutes: the must-use plugin in the sites in mode
      "plugins and themes", nothing in the sites in mode WordPress (master and node)
- [x] The three buttons on the site page change the mode; the file follows within a minute, with the core filter only in
      "everything"; WordPress itself (`apply_filters`) agrees
- [x] An automatic update in mode "plugins and themes" updates plugins and themes, not the core
- [x] Settings → Automatic updates off: the file goes from every site, the page says why; on again: it comes back
- [x] A site found by the scan starts in mode WordPress, with the hint on its page (checked before the three modes, with
      the switch of that time)
- [x] A file of the same name not written by the module is left alone, with a message (integration test; the page shows
      `native_message`)
- [x] `install.sh --uninstall` removes the file from the sites of its machine; the installer puts the module back and the
      worker the file

### Clone

- [x] Long domain to short domain, to another client: site works, source unchanged
- [x] Serialized arrays and objects, JSON, URL encoding, paths and tables without prefix are replaced
- [x] Email addresses, similar domains and `guid` stay as they are
- [x] A value in base64 is reported
- [x] Custom table prefix is kept
- [x] "Discourage search engines" is on in the clone, not in the source
- [x] Objects of plugin classes, with a real plugin (Elementor, WooCommerce): replaced, the caches of Elementor
      deleted and built again, the clone page without the old address; the same after an update of a staging
- [x] A staging at `staging.<domain>` does not report its own address as a mention of the domain
- [x] A large and busy site (2026-11-10, VM with 2 CPUs and 3 GB): 600 MB of uploads that do not compress, 30,000
      posts, two visitors without pause. Backup 112 s, plugin update 137 s, clone 308 s, all 200 to visitors except
      about 2 s of the maintenance page of WordPress during the plugin update. Worker memory below 50 MB. When ISPConfig
      creates the website of a clone it restarts Apache, like for any new site: about one second of errors on every
      site of the node (13 of 5,567 requests)

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

### Maintenance tools

- [x] Maintenance mode: 503 with `Retry-After` to visitors, still after a minute (the file sets the time at every
      request), the site with a login cookie and on the login page; kept by a restore of a backup taken without it and by a plugin update
- [x] One-click login: shown once, the button logs in, the worker sees the session and keeps the user 2 hours; at
      expiry the user is deleted, its post goes to an administrator and the session ends; an unused one goes too
- [x] A real administrator written in `login_user` is not deleted
- [x] Password reset: the shown password works; an ID that is not an administrator is refused by the panel
- [x] Debug: constants in `wp-config.php`, log in `private/` with the warning, `wp-content/debug.log` not there,
      off by itself after its time
- [x] Plugins: activate, deactivate, remove with backup; a plugin that breaks the pages is deactivated again
- [x] Themes: activate and back, remove an inactive one; the active theme cannot be removed
- [x] The same on a site of a slave node, with the column grants of the node

### Full removal

- [x] A site with a staging is refused; the staging goes first
- [x] A wrong domain or no tick removes nothing
- [x] Website, alias domain, FTP and shell users, cron job, databases and folder gone, also in MariaDB and on the
      system; a database user shared with a database of another website stays, with that database intact
- [x] The same for a site on a slave node
- [x] The removal is in the events

### Audit log

- [x] A row for each request of the panel, with user, address and parameter; the settings with what changed; a save
      that changes nothing adds no row; taking a site off the list too
- [x] The outcome of the worker next to each request; automatic events as done by nobody
- [x] No password in the log, also after a password reset that was shown
- [x] Filters, pages of 50, rows older than a year deleted at the next request; the last rows on the site page

### Security checks

- [x] Each hardening measure on and off: 403 where expected, uploads and pages still served, `.htaccess` back as before
- [x] Permissions: a file anyone can write and an open `wp-config.php` are reported and fixed; a file of root is only
      reported
- [x] A vulnerable plugin: listed with link and fixed version, email once with the notice of Defiant, automatic update
      at the next run (2026-11-16: with the update simulated in the database, because the network of the test
      VM resets the update check of WordPress; it started 30 s after, where the usual rule waits a day)
- [x] A new site gets the measures of the settings
- [x] A slave matches its sites against the feed downloaded by the master

### SSH keys

- [x] A new site with a key: SFTP with the key alone works, the fingerprint on the page is the one of `ssh-keygen -l`
- [x] Keys replaced from the site page: the old key is refused, the new one works; the passwords are not revealed
- [x] A key with options is refused and the stored keys stay

### Clients and resellers

- [x] A client sees its sites only: not the sites of another client, not settings, log or the admin menu items; a
      request with its own token on a site of another client does nothing
- [x] Limits: SFTP refused without shell users, another client and another web server refused, too much web space
      refused; a site within the limits is created with the space asked and belongs to the client; a second site
      refused when the space is over; real cron refused without cron jobs
- [x] A reseller sees the sites of its clients and may choose them in the new site form; it cannot remove them
      when ISPConfig gives its group only `ru` on their websites, as in the Sites module

### Sites in subfolders

- [x] The form refuses capitals, reserved names and a folder already managed; a site in `blog` of a website with
      WordPress in `web/` installs with its own database, as the site user, at the address of the website
- [x] Its page names the users of the website, has no clone, staging or SSH keys, and shows the WordPress password once
- [x] A backup of the site in `web/` leaves the blog out, and its restore leaves a file added to the blog after the backup
- [x] Backups, maintenance mode, debug and hardening of the blog stay in its folder
- [x] The site in `web/` cannot be removed while the blog exists; removing the blog deletes its folder, backups,
      database and user, and the site in `web/` keeps working
- [x] The same on a second node
- [x] An install found in a folder of a website is added by the scan, with the address of its folder

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
