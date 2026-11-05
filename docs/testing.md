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

The project is copied into the VM at `/opt/ispconfig-wp-manager`: run `sync` after every change.
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

Run each case first with `dry_run = true` (check the calls listed on the form), then with `dry_run = false`.

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
