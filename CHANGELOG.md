# Changelog

## Unreleased (0.1.0-dev)

- New sites from the panel, with SFTP or FTP access and per-site PHP version, language, plugins and theme
- Dry-run report on the form, settings page for the defaults, one-time view of the generated passwords
- Detection of existing WordPress installs
- Read-only update overview per node and per site
- Backups and restore, optionally without `wp-content/uploads`
- Updates with health check and automatic rollback, separate button for a new major version of WordPress
- Automatic updates of plugins, themes and minor core releases, on by default
- Clone to a new domain on the same node, serialized data included
- Switch between WP-Cron and a real cron job
- Updates run in the same PHP sandbox as the daily checks, which no longer lets code leave through FFI or `LD_PRELOAD`
- Fixes from the first run on ISPConfig 3.3.2: server module for `wpm_site`, `standard_index.html`, http URL
  without a certificate, list filter and sorting, spaced retries, unique names for long domains
- `dev/regression.sh` checks that existing sites did not change
- Project skeleton: ISPConfig interface module, server plugin, node worker, installer
- Shared library with validation, encrypted job secrets, safe WP-CLI command building, dry-run
- Unit tests and CI
