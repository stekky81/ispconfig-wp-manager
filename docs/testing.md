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

Never test on the production nodes. Use two disposable VMs that mirror production:

- **VM A**: ISPConfig master + web node (same OS and ISPConfig version as production)
- **VM B**: ISPConfig slave web node (to test multiserver)
- Take a snapshot of both VMs **before every test run** and revert afterwards.

## 3. Manual test checklist

Run each case first with `dry_run = true` (check the log), then with `dry_run = false`.

### Install

- [ ] Installer runs twice in a row without errors (idempotent)
- [ ] Installer after an ISPConfig update restores symlinks
- [ ] Module visible only to users who have it enabled

### New site — happy path

- [ ] Site on master node: website, DB user, DB (linked to the site), SFTP user created
- [ ] Site on slave node (VM B)
- [ ] WordPress reachable, admin login works, `wp-config.php` owned by `webN` with mode 0640 or stricter
- [ ] Secrets cleared from `wpm_site` after install
- [ ] Defaults applied (PHP version, SSL, plugins)

### New site — failure cases

- [ ] Domain already exists in ISPConfig → clear error, nothing created
- [ ] Client over quota → clear error, nothing created
- [ ] API fails halfway (e.g. wrong DB server id) → error lists created objects
- [ ] `web/` not empty → worker refuses, status `error`, no files touched
- [ ] WP-CLI missing on node → status `error` with message
- [ ] Node offline for 10 minutes → job runs when it is back
- [ ] Two sites created at the same moment → both installed, no mix-up

### Regression on existing sites

- [ ] Before/after checksum of every existing site's `web/` folder on both VMs: unchanged
- [ ] Existing databases unchanged

## 4. Pilot on production

Only after all checks pass: enable the module for one admin user, create one throwaway site
on each production node, verify, delete it. Then use it for real sites.
