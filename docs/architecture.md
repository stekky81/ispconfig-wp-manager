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

## Settings

Global defaults for new sites are edited in the panel (WordPress → Settings) and stored in `wpm_setting` on the
master, on top of `config.php`. The record of each site carries everything the worker needs from them, so the
nodes do not read that table. `dry_run`, the Remote API account and the key file can only be set in `config.php`.

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

## Open questions

- **Multiserver grants (to do before multiserver tests)**: slave nodes reach the master's
  dbispconfig as `ispcsrvN` users with per-table grants. The worker on a slave writes to
  `wpm_site` on the master, so the master installer must grant INSERT and UPDATE on `wpm_site` to every
  `ispcsrvN` user (and to new ones when a node is added).

- Remote API vs internal datalog calls for creating ISPConfig objects (Remote API chosen for 0.2:
  it is the documented, validated path).
- How to store the shared key on each node (currently a file readable only by root).
