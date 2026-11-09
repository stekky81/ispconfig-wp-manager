# Security

This module runs as root on web nodes and handles sites of different clients, so its design assumes that a site owner
is hostile: everything read from a site (files, options, WP-CLI output) is untrusted.

The review below was done on 2026-11-06 by reading the code and by attacking the test VM. It is not a professional
penetration test, and multiserver setups were not covered. Please report problems privately to the maintainer.

## Who can do what

| Actor | Can | Cannot |
|-------|-----|--------|
| ISPConfig admin | Everything in the module | Change `dry_run`, the Remote API account or the key from the panel |
| Client or reseller with the module enabled | Nothing yet: every page refuses | |
| Site owner | Their own files and database, and what ISPConfig lets one client's sites share (see below) | Read `secret.key`, `config.php` or the job spool, or reach the sites of another client |
| Panel user (`ispconfig`) | Read `config.php` and `secret.key` | Change `config.php` or anything root runs |

## How it is protected

- **Root runs nothing that others can change.** `/opt/ispconfig-wp-manager` belongs to root and `config.php` is
  `0640 root:ispconfig`. The worker and the server plugin refuse a `config.php` owned by another user or writable by group
  or others. Re-run the installer to fix permissions.
- **Root does not open site files.** Everything inside a site runs through `sudo -u webN`. Root only checks paths.
- **Data between two sites moves through a pipe** between their two users.
- **What starts by itself is sandboxed.** Daily checks, updates, backups, restores and the WP-Cron switch run with
  `open_basedir` limited to the site, a memory limit, and no `exec` and similar functions, FFI, `putenv` or sendmail.
  This limits PHP, it is not a jail: see the first risk below.
- **Output of a site is limited** to 32 MB per command and 2 MB per page, so it cannot fill the memory of root.
- **Disabled sites** are not checked and not updated.
- **Every state change in the panel needs a CSRF token**, and every printed value is escaped.
- **Values from a site are validated** before reaching a command. Commands are argument lists, never shell strings.
- **Passwords** travel on stdin, are masked in logs and are encrypted at rest with the shared key.

## Remaining risks

Known and accepted for now:

- **WP-CLI outside the jail.** See the next section: it decides whether this module fits your server.
- **A clone to another client** runs the code of the source in the account of that client, by nature, and that code can
  read the other sites of that client. Only an admin can do it, also once the module opens to clients, and the panel asks
  for a confirmation. Do it only for sites you trust.
- **A compromised panel** can read `secret.key`, so it can decrypt stored secrets, and it can use the Remote API account.
  It cannot become root through this module. To keep fewer secrets around, the generated passwords nobody looked at are
  deleted 7 days after the site is ready.
- **Encrypted secrets stay in `sys_datalog`** after they are cleared from `wpm_site`: the record of each change keeps
  them, the reveal included. ISPConfig deletes datalog records older than 7 days once every node applied them
  (`server/lib/classes/cron.d/200-logfiles.inc.php` in 3.3.2), so they stay there about as long as the passwords that
  nobody looked at. Deleting them sooner would mean changing an internal table of ISPConfig that keeps the nodes in
  sync, which is a larger risk than a few days. On a node of a multiserver setup the local copy of `wpm_site` also keeps
  them, but no panel runs there and the database of ISPConfig on a node is not open to site users.
- **After a timeout** a command gets a termination signal, and a process that ignores it keeps running as the site user.
- **Backups** belong to the site user, who can read and change them. A restore trusts their content as much as the site:
  a changed backup can bring back any file or row, but its dump cannot give commands to the `mysql` client.

## WP-CLI runs outside the jail

Read this before using the module on a server where site owners are jailed and you do not trust them.

WP-CLI runs on the node as the site user, but with the PHP CLI: outside jailkit and without the PHP limits of the site.
Code that the site owner controls (`wp-config.php`, must-use plugins, a `wp-cli.yml` that requires PHP) runs at these
moments:

| Moment | Sandbox |
|--------|---------|
| Daily check | Yes |
| Plugin, theme and core update, with its backup and rollback | Yes |
| Backup and restore | Yes: only the database settings are read in it, then `mysqldump` and `mysql` start from the worker |
| WP-Cron switch | Yes |
| Install | **No** |
| Clone | **No** |

Install and clone stay outside because they need WP-CLI to start other programs, and they only follow a request of an
admin. A clone loads the code of the source.

Code that runs there can start programs, keep a process running and read what the site user can read, outside the jail.
It still runs as the site user. It does not become root, and it cannot read the sites of other clients. In the test VM
the sites of **one** client can read each other, database passwords included, with or without this module: that comes
from the permissions ISPConfig gives them.

What to do:

- If your clients have a normal shell, or you are the only owner of the sites, this adds nothing to what they can do.
- If you rely on the jail to contain your clients, clone only sites whose owner you trust. A new install starts in an
  empty folder, so there is no code of the owner to run yet.

The sandbox itself is a limit on PHP and not a wall. It was tested against FFI, `LD_PRELOAD` and Imagick, but other
extensions loaded on your node may offer another way.

## What the review found

Fixed:

| Severity | Finding |
|----------|---------|
| High | `config.php` belonged to the panel user and root ran it every minute: panel access meant root |
| High | The new site form skipped CSRF and validators when it had not been opened in that session |
| High | A dump changed by the site user started programs outside the jail during a restore, through `\!` in the `mysql` client |
| High | The sandbox could be left through FFI and through `putenv(LD_PRELOAD)` with `mail()` or `error_log()`, so a must-use plugin ran programs during the daily check |
| Medium | Must-use plugins ran outside the jail during the daily check |
| Medium | Endless output of a site could fill the memory of the root worker |
| Medium | `wp-cli.yml` of the source was copied to a clone and read by WP-CLI as the clone user |
| Low | The `home` option of the source reached WP-CLI as an argument, and stayed in the clone |
| Low | The list and delete pages had no admin check |
| Low | A failed restore could not run again and could leave the database dump behind |
| Low | The database password stayed encrypted in `wpm_site` after a final install error |
| Low | Generated passwords nobody looked at stayed encrypted in `wpm_site` for ever |
| Low | The worker log was readable by every user, and an unreadable backup folder could stop the worker |
