# Help test WPStation

WPStation is pre-release. It has run end to end on our test servers, and the setups we could not try are listed in
[compatibility.md](compatibility.md) as "Not tested". An hour of your time on one of them helps more than anything else.

> [!CAUTION]
> **Test on a disposable server only**: a new VM or a cheap cloud server, never a server with real sites or clients.
> WPStation creates websites, databases and system users, runs commands as root and updates WordPress sites on its own.

## Most wanted

Reports on any of these are especially welcome:

- **nginx** instead of Apache
- **Debian** 12 or 13, or an Ubuntu release other than 24.04
- **MySQL** instead of MariaDB
- A **database server separate** from the web server
- ISPConfig **3.3.0** or **3.3.1**
- **Real WordPress sites** copied from production, with their plugins and page builders: clone, staging and updates
  meet the most surprises there. Import a copy, never the live site

## 1. A test server with ISPConfig

Any VM or cloud server with 2 CPUs, 3 GB of memory and 20 GB of disk will do. On a fresh Debian or Ubuntu, the
ISPConfig autoinstaller sets everything up in 10 to 20 minutes:

```bash
wget -O - https://get.ispconfig.org | sh -s -- --use-php=system --no-mail --no-dns --no-firewall --no-quota \
    --i-know-what-i-am-doing
```

Add `--use-nginx` for nginx. The host name must be a full name that resolves to the server's own IP, such as
`ispc.example.test`. See the [autoinstaller guide](https://www.howtoforge.com/ispconfig-autoinstall-debian-ubuntu/).

On macOS or Linux, [`dev/test-env.sh`](../dev/test-env.sh) does the same in a local
[Multipass](https://multipass.run) VM and takes a snapshot, so you can go back to a clean server in a minute:
`./dev/test-env.sh create`, then `./dev/test-env.sh reset`. Settings such as `UBUNTU=22.04` or `WEB=nginx` are at the
top of the script.

Then install [WP-CLI](https://wp-cli.org) in `/usr/local/bin/wp`.

## 2. Install WPStation

Follow [installation.md](installation.md). In short:

1. In ISPConfig, create a remote user with the permissions listed there.
2. `git clone https://github.com/stekky81/ispconfig-wpstation /opt/ispconfig-wpstation`, then
   `sudo /opt/ispconfig-wpstation/install/install.sh --role=master --role=node` on a single server.
3. Put the remote user in `/opt/ispconfig-wpstation/config.php`.
4. Enable the module for your admin user: **System → CP Users → edit → Modules → wpstation**, log out and in again.

## 3. What to try

Each step takes a few minutes. Stop wherever you like and report what you got: a partial report is still useful.

1. **Dry-run.** Create a site from **WordPress → New WordPress site**: the form lists what it would do and changes
   nothing.
2. **A real site.** Set `'dry_run' => false` in `config.php` and create the site again. Within a few minutes the list
   shows it as installed; open it, log in to wp-admin with the password shown once on its page.
3. **An existing site.** Install WordPress by hand in another ISPConfig website (or copy one in). Within an hour, or
   right away after `sudo rm /var/spool/ispconfig-wpstation/.last-scan`, it appears in the list.
4. **Backup and update.** On a site with an older plugin, take a backup, then update from its page. Look at the
   result and the log.
5. **Clone and staging.** Clone a site to a new domain, then create a staging site of it and push a change back.
6. **Site in a folder.** From the new site form, follow the link to install WordPress in `example.com/blog`.
7. **Tools.** Maintenance mode, one-click login, debug mode, file permissions, hardening.
8. **Removal.** Remove a site from its page, then `install.sh --uninstall`.

The full checklist we use is in [testing.md](testing.md#3-manual-test-checklist).

## 4. Report

- Something broke or looked wrong: open a [bug report](https://github.com/stekky81/ispconfig-wpstation/issues/new?template=bug_report.yml).
- It worked, fully or in part: open a [compatibility report](https://github.com/stekky81/ispconfig-wpstation/issues/new?template=compatibility_report.yml)
  with your setup and the steps you tried. Working setups go into [compatibility.md](compatibility.md).
- Questions and ideas: [Discussions](https://github.com/stekky81/ispconfig-wpstation/discussions).
- A security problem: do not open an issue, see [SECURITY.md](../SECURITY.md).

Useful logs: `/var/log/ispconfig-wpstation.log` (the worker), `/var/log/apache2/error.log` or the nginx error log
(the panel), and the install log on the page of a site. Remove domains, IP addresses and passwords before you paste
them.
