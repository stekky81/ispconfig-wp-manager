# Roadmap

Planned work, by milestone. Tick items as they land (with the commit or PR).
Pre-release: no milestone is considered done until it has passed `docs/testing.md` in the test VM.

## 0.1 — Skeleton (in progress)

- [x] Shared library, installer, interface module, server plugin, node worker
- [x] Unit tests and CI (PHP 8.1–8.4, shellcheck)
- [x] Multipass test environment (`dev/test-env.sh`)
- [x] Module installs on ISPConfig 3.3.2 and appears in the panel menu
- [x] List and form pages load without errors in the panel
- [x] Verify everything marked `VERIFY` in the code against ISPConfig 3.3.x
      (tform/listform flow, templates, `paging_tpl`, Remote API parameters)
- [ ] Installer: run twice in a row, run after an ISPConfig update, `--uninstall`

## 0.2 — Usable MVP: new WordPress site

- [x] End-to-end create flow in the VM: dry-run, then real
- [x] **One-time reveal of the generated credentials** (WP admin, SFTP/FTP) in a site detail page,
      then clear them from the database
- [ ] Optional email of the generated credentials to the admin
- [x] Site detail page: status, install log, links to the site, wp-admin and the ISPConfig website
- [x] Choice of SFTP or FTP access in the form, with password login
- [x] Per-site options in the form, pre-filled from the defaults: PHP version, locale, plugins, theme
- [ ] Clear status messages for every failure case in `docs/testing.md`
- [x] **Detect existing WordPress installs** on every node (scan `/var/www/clients/*/web*/web`
      for `wp-config.php`) and register them in `wpm_site`, linked to the ISPConfig website
- [ ] Multiserver: grant INSERT and UPDATE on `wpm_site` to the `ispcsrvN` users; test with a slave VM
- [x] Settings page in the panel for the global defaults (instead of editing `config.php`)

## 0.3 — Inventory and update overview (read-only)

- [x] Scheduled check (worker): core, plugin and theme versions and available updates
- [x] Dashboard: per node and per site, filter "updates available"
- [ ] Optional daily/weekly summary email
- [ ] First pilot on the production nodes (read-only, so no risk to the sites)

## 0.4 — Safe updates

- [x] Backup before every update (files + DB dump), with retention
- [x] Update core / plugins / themes from the panel
- [x] Health check after the update (HTTP 200, no PHP fatal errors, wp-admin reachable)
- [x] Automatic rollback when the health check fails
- [ ] Bulk updates and scheduled auto-updates per site

## 0.5 — Clone and staging

- [ ] Clone a site to a new domain or subdomain (files, DB, `search-replace`)
- [ ] Clone: replace the old domain everywhere in the database, **including serialized fields**, as
      Duplicator does on restore (`wp search-replace`, every table of the site, `guid` left alone). Also cover
      what is not PHP serialization: JSON with escaped slashes (page builders), URL-encoded values, `http` and
      `https`, `www`; report what could not be replaced, such as base64 values
- [ ] Clone across nodes
- [ ] Staging copy with "push to production" (with backup first)

## 1.0 — Complete product

- [ ] Maintenance: maintenance mode, admin password reset, one-click wp-admin login,
      debug toggle, plugin/theme activate/deactivate/remove
- [ ] SFTP login with SSH keys instead of a password
- [ ] Security: known-vulnerability check for plugins/themes (check the data feed's terms of use),
      file permission check, hardening for existing sites
- [ ] Clients and resellers can use the module, with quota checks (the Remote API does not enforce them)
- [ ] Full site removal (website, DB, users) with double confirmation and a final backup
- [ ] Audit log (who did what, when) and notifications (install done, update failed, vulnerability)
- [ ] Declared compatibility matrix (ISPConfig, Ubuntu/Debian, PHP versions)
- [ ] User documentation (not only installation)
- [ ] Docker-based integration tests in CI for `lib/` (MariaDB + WP-CLI, no ISPConfig)
