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
| Site owner | Their own files and database | Read `secret.key`, `config.php` or the job spool, or reach another site |
| Panel user (`ispconfig`) | Read `config.php` and `secret.key` | Change `config.php` or anything root runs |

## How it is protected

- **Root runs nothing that others can change.** `/opt/ispconfig-wp-manager` belongs to root and `config.php` is
  `0640 root:ispconfig`. The worker and the server plugin refuse a `config.php` owned by another user or writable by group
  or others. Re-run the installer to fix permissions.
- **Root does not open site files.** Everything inside a site runs through `sudo -u webN`. Root only checks paths.
- **Data between two sites moves through a pipe** between their two users.
- **Daily checks are sandboxed.** They run with `open_basedir` limited to the site, no way to start programs and a
  memory limit, because nobody starts them by hand and WordPress loads during them.
- **Output of a site is limited** to 32 MB per command and 2 MB per page, so it cannot fill the memory of root.
- **Disabled sites** are not checked and not updated.
- **Every state change in the panel needs a CSRF token**, and every printed value is escaped.
- **Values from a site are validated** before reaching a command. Commands are argument lists, never shell strings.
- **Passwords** travel on stdin, are masked in logs and are encrypted at rest with the shared key.

## Remaining risks

Known and accepted for now:

- **WP-CLI outside the jail for actions started by an admin.** Install, update, backup, restore and clone load
  `wp-config.php` and must-use plugins with the PHP CLI, as the site user but outside jailkit and the limits of the site.
  `--skip-plugins` does not stop must-use plugins. These commands cannot use the sandbox of the daily checks, because they
  need tools like `mysqldump`. A site owner can use one of these moments to run code unjailed, as their own user.
- **A clone to another client** runs the code of the source in the account of that client, by nature. The panel asks
  for a confirmation; do it only between clients you trust.
- **A compromised panel** can read `secret.key`, so it can decrypt stored secrets, and it can use the Remote API account.
  It cannot become root through this module.
- **Encrypted secrets stay in `sys_datalog`** until ISPConfig prunes it, also after they are cleared from `wpm_site`.
- **After a timeout** a command gets a termination signal, and a process that ignores it keeps running as the site user.
- **Backups** belong to the site user, who can read and change them. A restore trusts their content as much as the site.

## What the review found

Fixed:

| Severity | Finding |
|----------|---------|
| High | `config.php` belonged to the panel user and root ran it every minute: panel access meant root |
| High | The new site form skipped CSRF and validators when it had not been opened in that session |
| Medium | Must-use plugins ran outside the jail during the daily check |
| Medium | Endless output of a site could fill the memory of the root worker |
| Medium | `wp-cli.yml` of the source was copied to a clone and read by WP-CLI as the clone user |
| Low | The `home` option of the source reached WP-CLI as an argument, and stayed in the clone |
| Low | The list and delete pages had no admin check |
| Low | A failed restore could not run again and could leave the database dump behind |
| Low | The database password stayed encrypted in `wpm_site` after a final install error |
| Low | The worker log was readable by every user, and an unreadable backup folder could stop the worker |
