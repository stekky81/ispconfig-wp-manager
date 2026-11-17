# Compatibility

What this module has run on, and how far. **Pre-release**: none of it has run on production servers yet. The pilot on
production will add rows to this page.

Levels used below:

- **Tested**: the manual test plan in [testing.md](testing.md) passed on it, in the test VMs.
- **CI**: the automated tests run on it at every commit; the panel and ISPConfig were not involved.
- **Not tested**: it may work, but nobody has tried. Reports are welcome.

## Tested

| Component | Version | Notes |
|-----------|---------|-------|
| ISPConfig | 3.3.2p1 (master, panel) and 3.3.3p2 (second web node) | Multiserver: one master and one node, joined with the autoinstaller. A node on a newer release than its master worked |
| ISPConfig update | 3.3.2p1 over itself | The module and its plugin stay enabled ([installation.md](installation.md#after-an-ispconfig-update)) |
| OS | Ubuntu 24.04 LTS, ARM64 | Both VMs |
| Web server | Apache 2.4.58, PHP-FPM | |
| PHP | 8.3 | The panel, the worker and the websites |
| Database | MariaDB 10.11 | On each server |
| WP-CLI | 2.12.0 | In `/usr/local/bin/wp` |
| WordPress | 7.1 | The current release during the tests; updates were tested from older 7.x releases. New installs get the latest release |

## CI

| What | Versions |
|------|----------|
| Unit tests and lint of `lib/`, the panel and the worker | PHP 8.1, 8.2, 8.3, 8.4 on Ubuntu (x86_64) |
| Integration tests of `lib/`: real users, folders, MariaDB, WP-CLI and WordPress, no ISPConfig | PHP 8.3, MariaDB 11.4, WP-CLI 2.12.0, Ubuntu 24.04 (x86_64) |
| `shellcheck` of the installer and the scripts in `dev/` | |

## Not tested

| Component | What to expect |
|-----------|----------------|
| ISPConfig 3.2 or older | Not supported: the module was written against the code of 3.3 |
| ISPConfig 3.3.0, 3.3.1 | Never tried. The module uses the Remote API, the datalog and the forms of the panel as they are in 3.3.2 |
| Debian 12 or 13, older Ubuntu releases | Should work: the module needs the packages listed in the [README](../README.md#requirements), nothing specific to Ubuntu |
| x86_64 servers with ISPConfig | The CI runs on x86_64, the VMs on ARM64: nothing in the module depends on the CPU |
| nginx | Never tried. A staging sets its `X-Robots-Tag` header with an Apache directive, which nginx does not read: search engines are still told to stay away by "Discourage search engines". The hardening switches of `.htaccess` (no PHP in uploads, sensitive files, XML-RPC) cannot work: each one checks its own address after the change and undoes it, with a message, when the server ignores `.htaccess` |
| MySQL instead of MariaDB | Should work; dumps and imports use the `mysql` and `mysqldump` clients of the node |
| A database server separate from the web node | `site_defaults.database_server_id` in `config.php` puts the databases of new sites on another server; never tried |
| PHP 8.0 or older | Not supported: the module needs 8.1 or newer. Websites may use any PHP version WordPress supports, including additional PHP versions of ISPConfig |
| Mail and DNS servers of ISPConfig | Not needed and not touched. The DNS record of a new site is up to the admin |

## Requirements

The list of packages, PHP extensions and the Remote API account is in the [README](../README.md#requirements) and in
[installation.md](installation.md).
