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
- **SQL of the module** (objects of plugins and caches after a clone or a sync) runs as the site user, on stdin, with
  `--binary-mode`. Values of the site travel as hex in both directions and names are quoted, so no text of a site
  becomes SQL. Serialized values of a site are read by a parser of the module, never by `unserialize()` in root.
- **Passwords** travel on stdin, are masked in logs and are encrypted at rest with the shared key. They are never
  sent by email.
- **Maintenance tools**: the one-click login creates a temporary administrator, deleted after 15 minutes if unused and
  2 hours after the login; only users with its form of name are ever deleted. Its password and the one of a reset are
  shown once and expire; the debug log stays outside the web root and debug turns itself off after 24 hours.
- **Emails**: recipients are checked one by one (no line breaks, no headers can be added through them); text from a
  site or a command is stripped of control characters and cut before it goes into a message.

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
- **Backups** belong to the site user, who can read, change and delete them. A restore trusts their content as much as
  the site: a changed backup can bring back any file or row, but its dump cannot give commands to the `mysql` client.
  They exist for the rollback of an update and for going back after a change, not for disaster recovery: a hacked site
  can infect its own backups, so do not use them to clean it. Keep the backups of ISPConfig, or external ones, for that.

- **One-click login on a site without HTTPS** sends the temporary password, and then the session cookie, unencrypted,
  as any login on that site would. The page says so. Security plugins that move the login page or ask for a second
  factor stop the button; the page then shows user and password for a login by hand.
- **The maintenance mode lets through any browser with a cookie named like the login cookie of WordPress.** That shows
  the site, not wp-admin: WordPress checks the cookie itself. It hides a site from visitors, it does not protect it.

- **Full removal** deletes for good, with no backup of the module: the admin types the domain and ticks a box. Only
  admins can do it, as everything in the module for now.

- **Audit log**: written by the panel only; nodes have no grant on `wpm_audit`. An admin with access to the database
  of ISPConfig can still change it: it records what happened through the panel, it is not tamper-proof.

- **Vulnerability data** come from Wordfence through a key in `config.php` (0640 root:ispconfig, like the rest of it).
  Any node can write the rows of the feed in the master database: a node that was broken into could add false
  vulnerabilities (which can only start automatic updates and emails) or remove real ones from the check.
- **Hardening and permissions** change files as the site user only, and put them back if the site breaks; they never
  change the owner of a file.

- **SSH keys**: plain public keys only, without options; they reach the node through ISPConfig, like any change of
  a shell user.

## Multiserver

Reviewed on 2026-11-16, with a node joined to the master in the test environment.

- **A node writes to the master database** as its `ispcsrvN` account, like ISPConfig itself. The master installer gives
  it SELECT and INSERT on `wpm_site` (INSERT for the sites it detects) and UPDATE only on the columns a worker writes
  (status, messages, versions, secrets it clears, action fields, check times), like ISPConfig does with its own
  tables. The folder, the user, the server, the domain and the automatic update switch of a site cannot be changed
  from a node.
- **The worker checks every row before acting on it**: the row must point to a website of ISPConfig on its own node,
  with the same folder, user and domain (`SiteRecord`). A node that was broken into could still add rows; they are
  refused, and a refused row is logged and does not block the other sites.
- **The shared key is on every node**, so root on one node can decrypt the secrets stored for sites on the others: the
  database password until the install ends, the generated passwords until they are shown or expire after 7 days.
  A key per node would remove this; open for now.
- **Clones between nodes** go through the master database, encrypted with the shared key. A node that was broken into
  could open a request for any site of another node: the source node sends a site only for a clone that the panel
  created, which it checks in `sys_datalog`, where nodes cannot add records. With the shared key such a node can still
  read the pieces of a real clone that pass through the master database while it runs.
- **Events and summaries**: a node can add and delete rows of `wpm_event`, and add rows to `wpm_notice`. At worst a node
  that was broken into hides events from the summary or stops summaries; it cannot change a site.
- **Not ours**: ISPConfig needs the master database to accept connections from the nodes. Limit it to their addresses
  with a firewall.

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
| Staging: update from production and push to production | Yes, with the database copied by `mysqldump` and `mysql` between the two site users |
| Install | Yes |
| Clone | Yes, with the database copied by `mysqldump` and `mysql` between the two site users |
| Maintenance tools: users, debug, plugins and themes | Yes; the maintenance file and the debug log are written and read by `tee`, `grep`, `rm` and `tail` as the site user |

Since 2026-11-16 nothing of the module runs WP-CLI outside the sandbox: a clone no longer uses `wp db export` and
`wp db import`, which start programs.

Code that runs there can start programs, keep a process running and read what the site user can read, outside the jail.
It still runs as the site user. It does not become root, and it cannot read the sites of other clients. In the test VM
the sites of **one** client can read each other, database passwords included, with or without this module: that comes
from the permissions ISPConfig gives them.

What to do:

- If your clients have a normal shell, or you are the only owner of the sites, this adds nothing to what they can do.
- If you rely on the jail to contain your clients, keep in mind that the sandbox below is the only limit at these
  moments: clone only sites whose owner you trust.

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
| Medium | A command that ignored SIGTERM after a timeout kept running, and the `sudo` waiting for it kept the lock, so the worker of the whole node stopped |
| Medium | `wp-cli.yml` of the source was copied to a clone and read by WP-CLI as the clone user |
| Low | The `home` option of the source reached WP-CLI as an argument, and stayed in the clone |
| Low | The list and delete pages had no admin check |
| Low | A failed restore could not run again and could leave the database dump behind |
| Low | The database password stayed encrypted in `wpm_site` after a final install error |
| Low | Generated passwords nobody looked at stayed encrypted in `wpm_site` for ever |
| Low | The worker log was readable by every user, and an unreadable backup folder could stop the worker |
| Medium | A node could change every column of `wpm_site`, and the worker trusted the folder and the user of a row: a node that was broken into could send the worker of another node to another folder or user |
| Medium | Install and clone ran WP-CLI outside the sandbox; a clone ran the code of the source there |
| Medium | (design) Turning debug on with `WP_DEBUG` first would show errors to visitors if the next step failed: the display goes off and the log moves first |
| Low | (design) A node could name a real administrator in `login_user` and have it deleted at expiry: only names of the one-click login are deleted, and the column is cleared |
