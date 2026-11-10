# Changelog

## Unreleased (0.1.0-dev)

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
- Fixes from the first run on ISPConfig 3.3.2: server module for `wpm_site`, `standard_index.html`, http URL
  without a certificate, list filter and sorting, spaced retries, unique names for long domains
- `dev/regression.sh` checks that existing sites did not change
- Project skeleton: ISPConfig interface module, server plugin, node worker, installer
- Shared library with validation, encrypted job secrets, safe WP-CLI command building, dry-run
- Unit tests and CI
