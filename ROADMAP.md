# Roadmap

Planned work, by milestone. Tick items as they land (with the commit or PR).
Pre-release: no milestone is considered done until it has passed `docs/testing.md` in the test VM.

**Next:** to be decided (staging is done, see [docs/architecture.md](docs/architecture.md#staging)). See
[docs/security.md](docs/security.md) for the security review and what it left open.

## 0.1 — Skeleton

- [x] Shared library, installer, interface module, server plugin, node worker
- [x] Unit tests and CI (PHP 8.1–8.4, shellcheck)
- [x] Multipass test environment (`dev/test-env.sh`)
- [x] Module installs on ISPConfig 3.3.2 and appears in the panel menu
- [x] List and form pages load without errors in the panel
- [x] Verify everything marked `VERIFY` in the code against ISPConfig 3.3.x
      (tform/listform flow, templates, `paging_tpl`, Remote API parameters)
- [x] Installer: run twice in a row, run after an ISPConfig update, `--uninstall`

## 0.2 — Usable MVP: new WordPress site

- [x] End-to-end create flow in the VM: dry-run, then real
- [x] **One-time reveal of the generated credentials** (WP admin, SFTP/FTP) in a site detail page,
      then clear them from the database
- [x] Optional email to the admin when a site is ready (decided: without the credentials, which stay one-time in the panel)
- [x] Site detail page: status, install log, links to the site, wp-admin and the ISPConfig website
- [x] Choice of SFTP or FTP access in the form, with password login
- [x] Per-site options in the form, pre-filled from the defaults: PHP version, locale, plugins, theme
- [x] Clear status messages for every failure case in `docs/testing.md`
- [x] **Detect existing WordPress installs** on every node (scan `/var/www/clients/*/web*/web`
      for `wp-config.php`) and register them in `wpm_site`, linked to the ISPConfig website
- [x] Multiserver: grant INSERT and UPDATE on `wpm_site` to the `ispcsrvN` users; test with a slave VM
- [x] Settings page in the panel for the global defaults (instead of editing `config.php`)

## 0.3 — Inventory and update overview (read-only)

- [x] Scheduled check (worker): core, plugin and theme versions and available updates
- [x] Dashboard: per node and per site, filter "updates available"
- [x] Optional daily/weekly summary email, and an immediate one for rollbacks and failures
- [ ] First pilot on the production nodes. Read-only only with automatic updates turned off first:
      they start as soon as `dry_run` is off

## 0.4 — Safe updates

- [x] Backup before every update (files + DB dump), with retention
- [x] Update core / plugins / themes from the panel
- [x] Health check after the update (HTTP 200, no PHP fatal errors, wp-admin reachable)
- [x] Automatic rollback when the health check fails
- [x] Automatic updates, on by default: plugins, themes and minor core releases, with a switch per site
  (no bulk updates: decided on 2026-11-05)
- [x] Separate button to move to a new major version of WordPress
- [x] Optional backups without `wp-content/uploads`, for large sites

## 0.4.1 — Site tools

- [x] Switch between WP-Cron and a real cron job (`DISABLE_WP_CRON` and an ISPConfig cron job)

## 0.5 — Clone and staging

- [x] Clone a site to a new domain or subdomain, on the same node (files, DB, `search-replace`)
- [x] Clone: replace the old domain everywhere in the database, **including serialized fields**, as
      Duplicator does on restore (`wp search-replace`, every table of the site, `guid` left alone). Also cover
      what is not PHP serialization: JSON with escaped slashes (page builders), URL-encoded values, `http` and
      `https`, `www`; report what could not be replaced, such as base64 values
- [x] Clone across nodes (through the master database, encrypted; only a clone, not a staging or a move)
- [x] Staging: decisions (see [architecture.md](docs/architecture.md#staging))
- [x] Staging: create one per site, without outgoing PHP mail and with `X-Robots-Tag: noindex`
- [x] Staging: update it from production
- [x] Staging: push everything to production, with the list of what is lost, typed confirmation, backup,
      maintenance mode, health check and automatic rollback

## 1.0 — Complete product

- [x] Full security review: access levels, permissions of files and folders, panel pages, worker and installer
- [x] Security review of multiserver, and a sandbox for installs and clones
- [x] Maintenance: maintenance mode, admin password reset, one-click wp-admin login,
      debug toggle, plugin/theme activate/deactivate/remove
- [x] SFTP login with SSH keys (the password stays, as the way in if a key is lost)
- [x] Security: known-vulnerability check for plugins/themes (Wordfence Intelligence, terms checked on 2026-11-16),
      file permission check, hardening for existing sites
- [ ] Clients and resellers can use the module, with quota checks (the Remote API does not enforce them);
      a clone to another client stays admin only
- [ ] Optional: install in a subfolder of an existing website (`example.com/blog`); the subfolder must
      be new or empty, the rest of the web root stays untouched
- [x] Full site removal (website, DB, users) with double confirmation; no backup of the module (decided 2026-11-16:
      the backups of ISPConfig or external ones bring a site back)
- [x] Audit log (who did what, when) and notifications (install done, update failed); notifications of
      vulnerabilities come with the vulnerability check
- [ ] Declared compatibility matrix (ISPConfig, Ubuntu/Debian, PHP versions)
- [ ] User documentation (not only installation)
- [ ] Docker-based integration tests in CI for `lib/` (MariaDB + WP-CLI, no ISPConfig)
