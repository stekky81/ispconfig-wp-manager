# Administrator guide

For the ISPConfig administrator who runs the module day to day. It assumes the module is installed as in
[installation.md](installation.md). How each feature works inside is in [architecture.md](architecture.md); what your
clients see is in the [client guide](client-guide.md).

> [!WARNING]
> Pre-release: see the warning in the [README](../README.md) and the [compatibility](compatibility.md) page.

## The menu

The module adds a **WordPress** menu to the panel:

| Item | What it is | Who sees it |
|------|------------|-------------|
| WordPress sites | Every WordPress site the module knows, with its status and updates | Admins; clients and resellers see their own |
| New WordPress site | Website, database, users and WordPress in one form | Admins, clients and resellers with the module |
| Updates | Read-only overview of the updates and known vulnerabilities, per node and per site | Same as the list |
| Log | Who asked for what, when, from where, and the outcome | Admins only |
| Settings | Defaults for new sites, automatic updates, emails, hardening | Admins only |

Each site has its own page (the domain in the list): status, links, passwords, updates, backups, tools, security and
its recent activity. Most buttons there start an action on the node. The node runs it within a minute or two, and the
page reloads by itself every 15 seconds while it runs. One action at a time per site.

## Before you turn off dry-run

`dry_run` in `/opt/ispconfig-wp-manager/config.php` is on after installation. It stops every change, on the master
(the form only lists what it would do) and on each node (its own `config.php`). It cannot be changed from the panel.

Turning it off on a node starts the worker for real, including **automatic updates of every WordPress site on that
node**, also the ones the module did not create. To look at a node first, turn off **Settings → Automatic updates**
before you change `dry_run`. Then turn them on again, or turn them off only for the sites that need it.

## New sites

**WordPress → New WordPress site.** Domain, client, server, title, admin user and email are required; PHP version,
language, plugins and theme come from the settings. Choose SFTP (a jailed SSH user) or FTP
for file access, and paste SSH public keys if the client has them.

On saving, the panel creates through the Remote API the website, the database user, the database and the SFTP or FTP
user. The record of the site then goes to the node, which waits for ISPConfig to create the website folder (usually
within a minute) and installs WordPress as the site user. The status in the list:

| Status | Meaning |
|--------|---------|
| queued | Waiting for the node, or for a retry after a failure (the details say when) |
| installing | WordPress is being installed |
| installed | Ready |
| error | Stopped for good: the details say which step failed and why |

A failed install is tried again after 5 and then 30 minutes. The node never installs into a web folder that is not
empty, and if it already wrote files it stops with `error` and does not clean up: look at the site before deleting
anything. If ISPConfig does not create the website folder within an hour, the site goes to `error`.

The DNS record of the domain is up to you, as for any website. Let's Encrypt (on by default in the settings) needs it
before the certificate can be issued.

**Passwords** (WordPress admin, SFTP or FTP) are generated and encrypted. The site page shows them **once**, with
"Show passwords", within 7 days; then they are deleted. They are never sent by email. If nobody looked at them in time,
set a new password for the SFTP or FTP user in the Sites module of ISPConfig, and use "New password" on the site page
for WordPress.

## Existing sites

Once an hour every node looks for WordPress in the websites of ISPConfig and adds what it finds to the list, with
origin "detected". It reads the version and changes nothing, but from then on **automatic updates apply to them** too,
like every other site. Turn them off on the page of a site that must not change.

There are two ways to take a site away:

- **Take off the list** (the icon in the list, admins only): forgets the site in the module. Nothing is deleted, and a
  site with WordPress comes back at the next scan.
- **Delete the site** (on its page): deletes everything, see [Removing a site](#removing-a-site).

## Updates

Once a day each node reads the versions of WordPress, plugins and themes of three sites per run, and the updates
available. **Updates** shows them all; the page of a site shows its own, with **Check now**.

**Automatic updates** (on by default) install plugins, themes and minor releases of WordPress, one site at a time, at
most once a day per site. Three switches stop them: `dry_run`, **Settings → Automatic updates**, and the switch on the
page of each site. A new major release of WordPress is never automatic: the site page shows a button for it. Check that
plugins and themes support the new release first.

Every update, automatic or not, follows the same steps:

1. The site must answer before the update; otherwise nothing starts.
2. A backup of files and database.
3. The update, without loading plugins and themes.
4. The home page and the login page are checked again. If something that worked before does not work now, the backup
   is restored by itself, an email is sent at once, and the same versions are skipped until a newer one appears.

The last outcome of each site appears in the list and on its page.

## Backups

**Create backup** on the site page saves files and database in the `tmp` folder of the site. The last 3 are kept, and
they count towards the quota of the website. **Restore** puts one back after a confirmation. These backups exist for
rollbacks: the site user can change them, so keep the backups of ISPConfig or your own for disasters.

On large sites, **Leave out uploads** makes backups faster and smaller. A restore of such a backup leaves
`wp-content/uploads` as it is.

## Clone and staging

- **Clone** copies a site to a new domain, on the same node or another one, as a new website of the same client (only an
  admin can choose another client). The source is only read. The old address is replaced everywhere in the database,
  serialized data included; the page of the clone lists anything that could not be replaced. A clone is public, with
  "Discourage search engines" on, and keeps the users and passwords of the source.
- **Create staging** makes one copy per site at `staging.<domain>`, on the same node, for trying changes. It sends no
  mail from PHP and asks search engines to stay away, but it has no password. Its page has two buttons:
  - **Update from production**: the staging becomes a fresh copy of production again;
  - **Push to production**: production becomes a copy of the staging, files and **whole database**. Orders, comments,
    users and form entries that reached production after the copy are lost. The page lists what it can see before you
    confirm by typing the domain. Production is backed up, in maintenance mode during the copy, and restored by itself
    if it works less well afterwards.

`wp-config.php` is never copied: constants added by hand on one side must be added by hand on the other.

## Site tools

On the site page:

- **Maintenance mode**: visitors get the maintenance page of WordPress (HTTP 503); logged-in users and the login page
  still work. It stays until you turn it off, also across updates and restores.
- **Log in to wp-admin**: creates a temporary administrator and opens wp-admin in a new tab. It is deleted 15 minutes
  later if nobody logs in, otherwise 2 hours after the login. On a site without HTTPS the page warns that the password
  travels unencrypted.
- **Administrators → New password**: a random password for that administrator, shown once. Its sessions are closed.
- **Debug**: errors go to `private/wp-debug.log` of the website, outside the web root, never to the pages. It turns
  itself off after 24 hours. **Show the last 200 lines** reads the log.
- **Plugins and themes**: activate, deactivate, remove. The site is checked before and after: an activation that
  breaks it is undone, and a removal takes a backup first. Removing a plugin deletes its files; what it keeps in the
  database stays.
- **WordPress cron**: switch between WP-Cron and a real cron job of ISPConfig every 5 minutes. The client needs a cron
  job left in its limits.

## Security

- **Known vulnerabilities** of WordPress, plugins and themes, from Wordfence Intelligence. You need a free API key of
  your own (see [installation.md](installation.md#known-vulnerabilities-optional)). A node downloads the data once a
  day; **Settings** shows the date of the last download. A new vulnerability of a site is emailed once. If a fixed
  version exists and automatic updates are on, the site is updated at the next run instead of waiting for its day.
  Without a fixed version nothing changes by itself: the page suggests deactivating the plugin.
- **File permissions**: the daily check counts files anyone can write, files of other users, and the mode of
  `wp-config.php`. **Fix the permissions** corrects what the site user owns and undoes it if the site breaks.
- **Hardening**: four switches per site, each reversible: no file editor in wp-admin, no PHP in uploads, hide sensitive
  files, no XML-RPC. The last one stops Jetpack and the WordPress mobile app. **Settings → Hardening of new sites**
  chooses those of new sites and clones (all but XML-RPC by default). On a web server that ignores `.htaccess` the
  switch says so and stays off.
- **SSH keys for SFTP**: one public key per line; saving replaces the keys of the SFTP user. The password stays.

## Clients and resellers

To give a client or a reseller the module, tick **wpmanager** among the modules of its panel user in ISPConfig.

They see and use the sites whose websites ISPConfig lets them see, with the same rights as in the Sites module: to read,
to change, to delete. A website moved to another client in ISPConfig moves in the module too. They can create sites,
clones and stagings for themselves (a reseller also for its clients), within the limits of the client and of its
reseller: websites, databases, database users, FTP or shell users, jailed shells for SFTP, web servers, PHP versions and
web space. A new site needs at least 500 MB of space left. Admins have no limits.

Settings, the whole log, **Take off the list** and clones to another client stay with the admins.

> [!NOTE]
> ISPConfig gives a reseller only the rights to read and change the websites of its clients, not to delete them: a
> reseller cannot delete the sites of its clients in this module either.

## Emails

**Settings → Emails.** They use the mail settings of ISPConfig (System → Main config → Mail). Recipients: up to 10
addresses, or the admin email of ISPConfig if empty.

- A notice when a site or a clone is ready (or failed for good), without passwords.
- An alert at once after a rollback, a failed rollback, or a failed backup, restore, update or push.
- A summary, daily or weekly (default), only when there is something to report.
- A notice of each new known vulnerability.

## The log

**WordPress → Log** lists every request made in the module: who, when, from which address, which site, and the outcome
on the node. Every view of a password is logged too. Changes the module makes by itself (automatic updates, the end of
a debug mode or of a temporary login) appear as "automatic". Rows are kept for a year. The page of each site shows its
last 10 rows.

## Removing a site

**Delete the site** on the site page lists everything that will go: the website with its domains, users and cron jobs,
its databases, and the database users that no other website uses. Type the domain and tick that you know the module
keeps **no backup**: after this, only the backups of ISPConfig or external ones bring the site back. A site with a
staging cannot be deleted until the staging is deleted.

## When something goes wrong

Where to look, in this order:

1. The **details** of the status in the list, and the message of the last action on the site page. They say which step
   failed and the likely cause.
2. **WordPress → Log**, for what was asked and what happened.
3. On the node: `/var/log/ispconfig-wp-manager.log` (the worker) and `/var/log/ispconfig/` (ISPConfig).

Common cases:

| What you see | What to do |
|--------------|------------|
| A new site stays `queued` | The node has not created the website yet. Check that the node runs ISPConfig's `server.sh` (its cron) and that `dry_run` is off in the node's own `config.php` |
| "The node cannot reach …" | The node has no internet or no DNS: WP-CLI downloads WordPress, plugins and themes from wordpress.org |
| "WordPress cannot connect to its database" | Check that the database and its user exist on the node and that MariaDB runs |
| "The disk of the node or the disk quota of the website is full" | Free space on the node, or raise the quota of the website: the backups of the module count towards it |
| "WP-CLI is missing on the node" | Install WP-CLI in `/usr/local/bin/wp` |
| "The plugin … does not exist on wordpress.org" | A warning only: the site works. Fix the name in the settings |
| An update was **rolled back** | The site is as it was. Try the update on a staging, or look for a fix of the plugin |
| A **rollback failed** | The site may be broken: restore a backup from its page, or from the backups of ISPConfig |
| The site page says an action is running for a long time | The worker runs one action at a time per node; large backups and clones take minutes. Check the worker log |
| No updates ever appear | The node may not reach `api.wordpress.org`. **Check now** on a site and look at its message |

## Turning it off

- One site: turn off its automatic updates on its page.
- Everything, at once: `dry_run => true` in `config.php` of each node. The worker then changes nothing.
- For good: `sudo ./install/install.sh --uninstall` on each server. Tables, keys, backups and websites stay.
