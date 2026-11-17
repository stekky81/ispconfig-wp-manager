# Changelog

## Unreleased (0.1.0-dev)

- Renamed from `ispconfig-wp-manager` to WPStation: module `wpstation`, tables `wpstation_*`, namespace
  `WPStation\`, paths `/opt/ispconfig-wpstation` and `/etc/ispconfig-wpstation`, worker `wpstation-worker.php`.
  Nothing ran on production under the old name, so there is no migration
- New sites from the panel, with SFTP or FTP access and per-site PHP version, language, plugins and theme
- Dry-run report on the form, settings page for the defaults, one-time view of the generated passwords
- Detection of existing WordPress installs
- Read-only update overview per node and per site
- Backups and restore, optionally without `wp-content/uploads`
- Updates with health check and automatic rollback, separate button for a new major version of WordPress
- Automatic updates of plugins, themes and minor core releases, on by default
- Clone to a new domain on the same node, serialized data included, also inside objects of plugin classes; caches
  of built pages (transients, Elementor) are deleted, and what is left is reported with its table, column and row
- Staging site, one per site: no mail from PHP, `X-Robots-Tag: noindex`; update it from production, push it to
  production with typed confirmation, backup, maintenance mode, health check and automatic rollback
- The health check after a change waits for the opcache of PHP, looks again at what seems broken, and says why
- A new site or clone that does not answer after the install gets a warning on its page
- The worker writes its PHP errors with date and time
- Install and clone run WP-CLI in the PHP sandbox too; a clone copies the database with `mysqldump` and `mysql`
  between the two site users
- Multiserver review: nodes can update only the status columns of `wpstation_site`, and the worker refuses a row that does
  not match its website in ISPConfig
- Clone to another node: files and database travel encrypted through the master database, only for a clone the panel
  asked for
- Emails with the mail settings of ISPConfig: site ready (without passwords), immediate alerts for rollbacks and
  failures, daily or weekly summary; recipients in the settings page
- Multiserver: the master installer grants the module tables to the database account of every node, the node
  installer creates the node's own `config.php`; tested with a second VM joined as a node
- Failures say which step failed, the likely cause and what to check; a plugin or theme that cannot be installed is a
  warning on an installed site; a retry continues over the files of its own earlier attempt instead of refusing them
- Switch between WP-Cron and a real cron job
- Updates, backups, restores and the WP-Cron switch run in the same PHP sandbox as the daily checks, which no longer lets
  code leave through FFI or `LD_PRELOAD`; backups use `mysqldump` and `mysql` directly, and a restore ignores client
  commands in a dump
- Generated passwords nobody looked at are deleted 7 days after the site is ready
- After a timeout, a command that ignores SIGTERM is killed with what it started, and can no longer keep the lock
  of the worker
- A clone to another client stays admin only, also once clients can use the module
- Fixes from the first run on ISPConfig 3.3.2: server module for `wpstation_site`, `standard_index.html`, http URL
  without a certificate, list filter and sorting, spaced retries, unique names for long domains
- `dev/regression.sh` checks that existing sites did not change
- Project skeleton: ISPConfig interface module, server plugin, node worker, installer
- Shared library with validation, encrypted job secrets, safe WP-CLI command building, dry-run
- Unit tests and CI
- Maintenance tools on the site page: maintenance mode (503 to visitors, the site to logged-in users, kept across
  updates, restores and pushes), one-click login with a temporary administrator, password reset of an administrator,
  debug mode for 24 hours with the log in `private/`, activation, deactivation and removal of plugins and themes with
  health check and rollback; the page looks again by itself while an action runs
- The health check sees the site behind the maintenance mode and past page caches
- Full removal of a site from its page: everything ISPConfig attaches to the website, its databases included, listed
  before a typed confirmation; refused while it has a staging or a clone of it is being installed. The icon of the list
  is now "Take off the list", which deletes nothing
- Audit log: every request of the panel and every view of a password, with user, address and outcome, kept for a
  year, on its own page with filters and on each site page; events are kept for a year too
- Security: known vulnerabilities from the Wordfence Intelligence feed, one download a day for all nodes, listed on the
  site page with the notice of Defiant, emailed once, fixed by an early automatic update when a fixed version exists;
  file permission check and fix; hardening switches per site, applied to new sites from the settings
- SSH keys for the SFTP user, in the new site form and on the site page, checked before they reach ISPConfig
- Clients and resellers: their own sites through their permissions on the websites in ISPConfig, new sites, clones and
  stagings within the limits of their package, checked by the panel
- Integration tests of `lib/` with real users, MariaDB, WP-CLI and WordPress, in the CI on every push
- Documentation: administrator guide, client guide and compatibility matrix
- WordPress in a folder of an existing website (`example.com/blog`): only a database and its user are created, the
  folder must be new or empty; backups, restores, clones and stagings of the site in `web/` leave such folders out;
  removal deletes the folder, its database and its user only; the scan finds installs one level below `web/`
- CI: the integration job installs the MariaDB client, and failed tests appear as annotations of the run
