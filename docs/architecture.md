# Architecture

## Components

| Path | Runs on | Role |
|------|---------|------|
| `interface/web/wpmanager/` | master (panel) | ISPConfig module: menu, forms, lists |
| `lib/` | master and nodes | Shared, framework-free classes (unit tested) |
| `server/mods-available/wpmanager_module.inc.php` | every node | Registers the `wpm_site` table with `server.sh`, raises `wpm_site_*` events |
| `server/plugins-available/wpmanager_plugin.inc.php` | every node | Receives the `wpm_site_*` events, queues jobs |
| `server/bin/wpm-worker.php` | every node (cron) | Executes queued jobs with WP-CLI |
| `install/` | master and nodes | Installer, SQL schema, cron file |

The project lives in `/opt/ispconfig-wp-manager`. The installer **copies** the interface module and the
server module and plugin into the ISPConfig tree (a symlink does not work: ISPConfig modules use relative paths
that PHP resolves from the symlink target). The shared library stays in `/opt` and is loaded by
absolute path. Re-run the installer after every ISPConfig update and after updating this project.

## New site flow

1. The admin fills in the form (domain, client, server, admin email; everything else from defaults).
2. `SiteProvisioner` (master) calls the ISPConfig Remote API, in this order:
   website → database user → database (linked to the website via `parent_domain_id`) → SFTP or FTP user,
   as chosen in the form. Password login only for now.
   A failure stops the sequence and the already-created objects are listed for cleanup.
3. The `wpm_site` record is saved through the datalog with `status = queued` and the job secrets
   (DB password, WP admin password) **encrypted** with the shared key (`Crypto`, libsodium).
4. On the target node `server.sh` passes the record to `wpmanager_module`, which raises `wpm_site_insert`;
   the plugin writes a job file to the spool.
5. `wpm-worker.php` (cron, every minute) picks the job when the vhost directory exists, checks
   that `web/` is empty, runs WP-CLI as the site user, then reports `installed` or `error`
   back to the master and clears the secrets.

The plugin never runs WP-CLI itself: ISPConfig applies datalog entries asynchronously and a slow
command inside `server.sh` would block every other change on the node.

## Update overview

Once a day, three sites per run, the worker reads core, plugin and theme versions and available updates with
WP-CLI, as the site user and with `--skip-plugins --skip-themes` (must-use plugins still load). It updates nothing: no file changes, and in the
site database WordPress only refreshes its own update transients. The output is under the control of the site
owner, so it is limited on the node and escaped in the panel. The report is stored in `wpm_site.updates_json`.

## Backups

Backups are taken by the worker as the site user, in `<site>/tmp/wpmanager-backups/<id>/`: database dump and files,
verified, then marked with a `COMPLETE` file. The last 3 are kept. They are meant for rollbacks, not as the backups
of the server: the site user can change them (see [security.md](security.md)). The folder was chosen after reading ISPConfig 3.3.2:

- `<site>/backup` is root-only and ISPConfig deletes files older than 3 days from it.
- A new folder next to `web` would need the immutable flag of the site folder removed, and ISPConfig would back it up.
- `<site>/private` would be included in the backups of ISPConfig.
- `<site>/tmp` counts towards the quota, is excluded from those backups and only loses `sess_*` files in the daily cron.

Exception: with backup mode `userzip` and a tar format ISPConfig ignores its excludes, so these files end up in its
archives. Nothing is lost, but they grow. Modes `rootgz`, zip formats and borg apply the excludes.

Large sites can leave `wp-content/uploads` out of their backups, with a switch on the site page. Such a backup carries a
`NO_UPLOADS` mark and a restore follows the mark, not the current switch, so uploads are never deleted by mistake.
After that restore, files uploaded since the backup stay on disk but the database no longer lists them.

A restore first verifies and unpacks the backup, and only then replaces `web/` and imports the database. Actions asked
in the panel reach the node through the datalog and run once: a failed restore never starts again by itself.

## Updates

An update runs on the node as the site user and never without a way back: the site must answer before it starts,
a backup must succeed, and the update runs with `--skip-plugins --skip-themes`. The home page and `wp-login.php` are then
checked again. What worked before and does not work now, or a failed command, restores the backup automatically.
The checks compare before and after, so a login page moved on purpose is not a failure. They call the local web server
directly: a vhost bound to a single IP address may need a different address.

Automatic updates are on by default: plugins, themes and minor core releases, never a new branch of WordPress. They
go through the same steps, one site per worker run and once a day per site at most. Three switches stop them: `dry_run`,
Automatic updates in the settings and the switch of each site. After a rollback the same set of versions is skipped until
a different version appears, otherwise a broken update would break the site again every day. The outcome appears in the
site list. The panel has no bulk update on purpose. A new branch of WordPress is only installed from its own button on the
site page, with the same checks and rollback.

## Clone

A clone is a new website of the same node, created like any other site, whose job copies a site instead of installing
WordPress. The source is only read, as its own user, and the clone is written as its own user: data travels through
a pipe between the two, so root never opens a file a site owner controls. `wp-config.php` is not copied, because it
would point the clone to the database of the source, and before any write the worker checks which database the
new one points to.

The old address is replaced with `wp search-replace --regex --all-tables`, which unserializes every value and serializes
it again, so lengths stay right. The rules in `SearchReplacePlan` cover http and https, `www`, addresses without a scheme,
JSON with escaped slashes, URL encoding and paths on disk, and they require the end of the host name, so `old.com`
does not match `old.company.it`. `guid` stays as it is, and so do email addresses and bare domains in a text.

WP-CLI runs without plugins, so it cannot rebuild an object of a plugin class (WooCommerce keeps some in options and
sessions) and skips it. `ObjectReplacer` then looks for serialized values that still mention the old site and passes
them to `SerializedReplacer`, which reads the serialized format itself, without unserializing: no object is created and
no code of a plugin runs. Only string values change, with their lengths written again; keys, property and class
names, numbers and references stay. A value it cannot read entirely (broken lengths, `C:` custom serialization, more
than 256 levels) is left as it is. It runs as the site user with the `mysql` client, with every value of the site as a
hex literal and every name quoted, and each `UPDATE` names the old value too.

Caches that hold pages built with the old address are deleted afterwards (`SiteCaches`): transients, and the element
cache and generated CSS of Elementor, whose element cache keeps HTML in base64. WordPress and the plugins build them
again on the first visit. A staging update and a push do the same two steps.

What is left is reported with where it is (table, column, row), including what looks like the old host inside base64
values, which no replace can reach.

A clone is public, with "Discourage search engines" turned on so that it does not compete with its source.

### To another node

Decided with the maintainer on 2026-11-16: a clone (not a staging, not a move) can go to any web node, and the data
travels through the master database, the only place both nodes reach, so no new access between nodes is needed.

1. The worker of the target node creates the website as usual, then adds a request to `wpm_transfer` and waits.
2. The worker of the source node claims it. It sends the site only if the panel asked for that clone: the insert of the
   target record in `sys_datalog`, which nodes cannot write, must name this source, this target and the target node
   (`TransferAuthorization`). The source record must also match its website.
3. It sends a description (home address, table prefix, read in the sandbox), the files (`tar` as the site user) and the
   dump (`mysqldump` as the site user), in pieces of 2 MB in `wpm_transfer_piece`. Each piece is encrypted with the
   shared key and carries the transfer, its number and its kind; the end of each stream is a piece of its own
   (`SiteTransfer`). At most 16 pieces wait in the master database: the source waits for the target to read them.
4. The target decrypts and checks every piece, unpacks the files as the clone user, loads the dump with
   `mysql --binary-mode`, deletes what it read, and goes on like any clone. A failure on one side is written in the
   transfer and stops the other side; the transfer is deleted at the end. Transfers older than a day are deleted.

The PHP version of a clone on another node is the default one of that node: PHP versions belong to their server.

## Staging

Decided with the maintainer on 2026-11-09, implemented in `SiteStaging` and `SiteSync`. A staging is a clone with a link to its production site, and the
two actions that make it a staging.

- **One staging per site**, on the same node and for the same client, at `staging.<domain>` by default (the DNS record
  is up to the admin, as for a clone). Its website gets the PHP version of the production site and two settings of its
  own, which live in the ISPConfig website and not in the files, so a push never carries them:
  - `sendmail_path = /bin/true` in its PHP settings: a copy of a shop has real customers, and its cron must not mail them.
    A plugin that talks to an SMTP server by itself still sends mail: the panel says so.
  - `Header set X-Robots-Tag "noindex, nofollow"` in its Apache directives, besides "Discourage search engines".
    No password: whoever knows the address can open it.
- **Update staging from production** replaces the files and the database of the staging with those of production,
  after a backup of the staging.
- **Push to production** copies **everything**, files and the whole database, the other way. A partial copy tends to
  leave other inconsistencies, so it is all or nothing, as in other control panels. Before it, the panel lists what will be
  lost: what reached production since the copy (orders, comments, users, form entries) and any core, plugin or theme
  version that is newer on production; the admin confirms by typing the production domain. Then the worker takes a
  backup of production, puts it in maintenance mode (`.maintenance`), copies, replaces the staging address, puts back
  the "Discourage search engines" value of production, leaves maintenance mode and runs the health check. If production
  works less than before, the backup is restored, as after an update.
- `wp-config.php`, `wp-cli.yml` and `.maintenance` are never copied, in either direction: constants added by hand on the
  staging have to be added by hand on production.
- Tables that exist only on the target stay, unused: the database may be shared with something that is not WordPress,
  so nothing outside the copied tables is dropped.

## WP-Cron

A site can move between WP-Cron and a real cron job. The job is an ISPConfig cron job of type URL, every 5 minutes, which
calls `wp-cron.php`, so it runs on the PHP version of the site. The worker adds or removes `DISABLE_WP_CRON` in
`wp-config.php`, and removing it leaves the file as it was. Both changes reach the node through the datalog: for a minute
or two no cron may run, and scheduled tasks simply run at the next pass.

## Maintenance tools

Decided with the maintainer on 2026-11-16. Every tool is an action of the site page, run once by the worker of the node
(`SiteActions` lists them and checks what the panel may ask for, against what the last check of the node saw). The
page looks again every 15 seconds while an action runs.

- **Maintenance mode** (`MaintenanceMode`): the `.maintenance` file of WordPress, written by the site user. WordPress
  ignores that file after 10 minutes, so this one sets the time at every request and lasts until it is turned off.
  Visitors get the maintenance page of WordPress, or `wp-content/maintenance.php` if the site has one, with HTTP 503
  and `Retry-After`; the login page and browsers with a WordPress login cookie see the site, so the admin can work.
  WordPress writes and removes its own `.maintenance` during an update: updates, restores and pushes from a staging
  put back the mode they found. The health check sends a login cookie that WordPress does not accept, so it sees
  the site behind the maintenance page, and past page caches.
- **One-click login** (`SiteUsers`): the worker creates a temporary administrator, `wpm-login-` and 8 hex digits, with
  a random password and an `.invalid` address; the page shows it once and its button posts it to `wp-login.php` in a
  new tab. The user is deleted 15 minutes later if nobody logged in, otherwise 2 hours after the login (the worker
  looks for its session every minute). What it wrote goes to the first administrator; without one it stays as a
  subscriber with an unknown password. The password cannot change after the login: the login cookie of WordPress
  holds a piece of the password hash, so a new password ends the session. Only names of that form are ever deleted.
  On a site without HTTPS the page says that password and session travel unencrypted.
- **Password reset** of an administrator: random password, shown once on the page for a day at most, every session of
  that user closed, no email.
- **Debug** (`WpDebug`): `WP_DEBUG_DISPLAY` off, `WP_DEBUG_LOG` in `<site>/private/wp-debug.log`, then `WP_DEBUG` on,
  in that order, so that errors never reach the pages. `wp-content/debug.log`, the default, can be downloaded by anyone.
  It turns itself off after 24 hours. The page shows the last 200 lines on request, read by the site user.
- **Plugins and themes** (`SiteExtensions`): activate, deactivate, remove. The state is read again before the action;
  the active theme and its parent cannot be removed. The site is checked before and after, like an update: an
  activation that breaks it is deactivated again (without loading the plugin), a deactivation is undone by activating
  again, a theme comes back to the previous one. Removal deletes the files only: what the plugin keeps in the
  database, and its translations, stay. `wp plugin delete` starts `rm`, which the sandbox forbids, so WP-CLI only
  gives the path of the plugin and the worker deletes its folder (or its one file) as the site user, after checking
  that it is inside the web root. A backup is taken first and restored if the site breaks. A plugin that breaks
  WordPress itself is deactivated without loading the other plugins, and its deactivation code does not run.

Everything runs as the site user, in the PHP sandbox; user commands load no plugin or theme. Passwords for the page
travel encrypted in `wpm_site.secret_result`, are cleared when shown and expire at `secret_until`.

## Full removal

Decided with the maintainer on 2026-11-16. "Delete the site" on the site page opens a page that lists everything that
will go, read again when the admin confirms: the website, its alias domains and subdomains, FTP/SFTP, shell and WebDAV
users, cron jobs, protected folders and their users, ISPConfig backup records, its databases, and the database users
that no database of another website uses. The admin types the domain and ticks that the module keeps no backup: after
this, only the backups of ISPConfig or external ones bring the site back.

The panel deletes the records through the datalog, as the Sites module of ISPConfig does (`web_vhost_domain_del.php` in
3.3.2), and the nodes remove files, vhosts, databases and users as for any change made in ISPConfig. Unlike that module
it also deletes the databases, which ISPConfig only detaches; a database user goes with a datalog record for every
server (`server_id` 0), like ISPConfig deletes one. A database that WordPress uses but that is not attached to the
website stays, and the page says so. `SiteRemoval` decides what goes and in which order: what hangs on the website
first, the website last.

Refused while the site is being installed, while an action runs, while a clone of it is being installed, and while it
has a staging site: the staging goes first. The removal is written to `wpm_event`, with the panel user. "Take off the
list", the icon of the list, still only drops the record of the module and deletes nothing.

## Audit log

Decided with the maintainer on 2026-11-16. The panel writes a row in `wpm_audit` for every request it accepts: new
site, clone, staging, every action of the site page, the switches of a site, update or push of a staging, removal,
taking a site off the list, changes of the settings (which key, from what to what), and every view of a password
(generated passwords, one-click login, password reset). Each row has the panel user, the address, the time, the site,
the action and its parameter; never a password. The worker writes the outcome in `wpm_event` as before; the log page
(WordPress → Log) shows next to each request the first event of its kind on its site within 24 hours, and also the
changes the worker makes by itself (automatic updates, the end of a debug mode or of a temporary login), as done by
nobody. Filters: site, user, action, period; 50 rows a page. The site page shows its last 10 rows.

Rows and events are kept for a year. The panel deletes older rows of `wpm_audit` when it writes a new one, so the
nodes need no access to it; the worker deletes older events with the summary.

## Settings

Global defaults for new sites are edited in the panel (WordPress → Settings) and stored in `wpm_setting` on the
master, on top of `config.php`. The record of each site carries what the worker needs to install it, and the worker
reads only the automatic updates switch and the email settings from that table. `dry_run`, the Remote API account and the key file can only be set in `config.php`.

## Emails

Decided with the maintainer on 2026-11-16. The worker of each node sends, with the mail settings of ISPConfig read from
the master (System → Main config → Mail: sender, SMTP server; the copy in the database of a node can be old):

- a notice when a new site or clone is ready, or failed for good, with a link to the site and **no password**: the
  passwords stay on the page of the site, shown once (an email travels in clear and stays in mailboxes and backups);
- an alert at once for a rollback (also a failed one) and for a failed action: backup, restore, update, update of a
  staging, push;
- a summary, off, daily or weekly (default), only when there is something to report: the problems of the period and
  the sites that are still broken (failed install, failed rollback), what happened in the period, updates available.
  An older warning stays on the page of its site, or every summary would repeat it. The workers record what they do in `wpm_event` on the master; the first
  node that adds the name of the summary to `wpm_notice` sends it, so a multiserver setup gets one email. It is due
  from 6:00, in the time of the master database. Events older than a year are deleted.

Recipients are set in the settings page (up to 10, separated by commas); empty means the admin email of ISPConfig.
Without a valid admin email the sender is `wpmanager@<node>`. `Notifications` builds the messages; a failed email is
written to the log of the worker and stops nothing.

## Existing sites

Once an hour the worker looks for `wp-config.php` in `/var/www/clients/client*/web*/web` and adds to `wpm_site`
the sites ISPConfig knows and the list does not have yet, with `origin = detected` and no secrets. It changes
nothing in the sites: it only runs `wp core version` as the site user. Removing such a site from the list is
therefore temporary. On a slave node this needs INSERT on `wpm_site`, see the multiserver note below.

## Safety rules (enforced in code)

- **Dry-run**: `config.php` → `dry_run = true` lists every Remote API call on the form (passwords masked)
  without executing it, and stores no record, so no job reaches the worker.
  Default in `config.example.php` is `true`.
- **Empty target only**: the worker refuses to install into a `web/` directory that contains anything
  other than the files ISPConfig creates (`index.html`, `standard_index.html`, `favicon.ico`, `robots.txt`,
  `stats/`, `error/`, `.well-known/`).
- **Path guard**: every path used by the worker must resolve inside `/var/www/clients/`.
- **Run as site user**: WP-CLI always runs as the site's `webN` user, never as root.
- **Only its own files overwritten**: a failed install is retried after 5 and then 30 minutes. A retry runs again over
  `web/` only when the earlier attempt of the same job found it empty and left it not empty; the job keeps that flag in
  the spool, which only root can change. Anything else in `web/` stops the install for good.
- **Failures in plain words**: the status says which step failed, the likely cause (no internet or DNS, database
  refused, disk or quota full, timeout, unknown plugin or theme, WP-CLI missing) and keeps the output as details
  (`FailureMessage`). A plugin or theme that cannot be installed gives a warning, not an error: WordPress works without it.
  If WP-CLI already wrote files, the job ends in `error` and needs a manual look — never an automatic cleanup.
- **Passwords shown once**: the site detail page decrypts the WP admin and SFTP/FTP passwords on request,
  after clearing them from `wpm_site`. Encrypted copies stay in `sys_datalog` until ISPConfig prunes it.
- **Secrets out of `ps`**: passwords reach WP-CLI through stdin (`--prompt`), never as arguments.
- **Only temporary users deleted**: the worker deletes a WordPress user only if its name has the form of the one-click
  login, whatever `wpm_site.login_user` says.
- **No shell interpolation**: commands are built as argument arrays and escaped one by one.

Permissions, access levels and remaining risks are in [security.md](security.md).

## Open questions

- **Multiserver grants**: slave nodes reach the master's dbispconfig as `ispcsrvN` users with per-table grants. The
  worker on a node reads and writes `wpm_site` (INSERT for the sites it detects) and reads `wpm_setting`, so the
  master installer grants SELECT, INSERT and UPDATE on `wpm_site` and SELECT on `wpm_setting` to every `ispcsrvN`
  account it finds; it has to run again when a node is added. Times that the master writes (`secret_since`) are
  compared with the clock of the master database, not with the clock of the node.

- Remote API vs internal datalog calls for creating ISPConfig objects (Remote API chosen for 0.2:
  it is the documented, validated path).
- How to store the shared key on each node (currently a file readable only by root).
