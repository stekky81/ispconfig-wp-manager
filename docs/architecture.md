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
verified, then marked with a `COMPLETE` file. The last 3 are kept. The folder was chosen after reading ISPConfig 3.3.2:

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
does not match `old.company.it`. `guid` stays as it is, and so do email addresses and bare domains in a text. What is left
is reported, including what looks like the old host inside base64 values, which no replace can reach.

A clone is public, with "Discourage search engines" turned on so that it does not compete with its source.

## WP-Cron

A site can move between WP-Cron and a real cron job. The job is an ISPConfig cron job of type URL, every 5 minutes, which
calls `wp-cron.php`, so it runs on the PHP version of the site. The worker adds or removes `DISABLE_WP_CRON` in
`wp-config.php`, and removing it leaves the file as it was. Both changes reach the node through the datalog: for a minute
or two no cron may run, and scheduled tasks simply run at the next pass.

## Settings

Global defaults for new sites are edited in the panel (WordPress → Settings) and stored in `wpm_setting` on the
master, on top of `config.php`. The record of each site carries what the worker needs to install it, and the worker
reads only the automatic updates switch from that table. `dry_run`, the Remote API account and the key file can only be set in `config.php`.

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
- **No half-installs overwritten**: a failed install is retried, after 5 and then 30 minutes, only while `web/` is still pristine.
  If WP-CLI already wrote files, the job ends in `error` and needs a manual look — never an automatic cleanup.
- **Passwords shown once**: the site detail page decrypts the WP admin and SFTP/FTP passwords on request,
  after clearing them from `wpm_site`. Encrypted copies stay in `sys_datalog` until ISPConfig prunes it.
- **Secrets out of `ps`**: passwords reach WP-CLI through stdin (`--prompt`), never as arguments.
- **No shell interpolation**: commands are built as argument arrays and escaped one by one.

Permissions, access levels and remaining risks are in [security.md](security.md).

## Open questions

- **Multiserver grants (to do before multiserver tests, which are set aside for now)**: slave nodes reach the master's
  dbispconfig as `ispcsrvN` users with per-table grants. The worker on a slave writes to
  `wpm_site` on the master, so the master installer must grant INSERT and UPDATE on `wpm_site` to every
  `ispcsrvN` user (and to new ones when a node is added).

- Remote API vs internal datalog calls for creating ISPConfig objects (Remote API chosen for 0.2:
  it is the documented, validated path).
- How to store the shared key on each node (currently a file readable only by root).
