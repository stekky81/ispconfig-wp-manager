# Your WordPress sites: client guide

Your hosting provider manages WordPress sites with this module. This guide explains what you can do from the
**WordPress** menu of your control panel. If you do not see that menu, ask your provider to turn it on.

What you can do depends on your hosting package: the number of sites, the disk space and the kind of file access.
When a limit is reached the panel says which one.

## Your sites

**WordPress → WordPress sites** lists your sites. Click a domain to open its page. From there:

- **Open site** and **Open wp-admin** take you to the site and to its dashboard;
- the status, the WordPress version and the updates available;
- every tool described below.

When you press a button, the server does the work within a minute or two. The page shows that it is busy and reloads
by itself. Only one task at a time runs on each site.

## A new site

**WordPress → New WordPress site.** Fill in:

- **Domain**: the address of the site, for example `example.com`. It must point to your hosting, or the site cannot
  be reached and gets no HTTPS certificate: ask your provider if you are not sure.
- **Site title**, **WordPress admin user** and **WordPress admin email**: the email receives the messages of WordPress,
  such as a lost password.
- **File access**: SFTP (secure, recommended) or FTP, to upload files with a program such as FileZilla.
- **Web space**, if your package has a limit: a WordPress site needs at least 500 MB.

The site is ready within a few minutes. Its status then reads **installed**.

### A site in a folder

The page **New WordPress site** has a link to put WordPress in a folder of a website you already have, for example
`yourdomain/blog`. Choose the website and the folder name: lowercase letters, digits and hyphens. The folder must be new
or empty. The site uses the file access and the disk space of that website, so only the WordPress password is new. Your
other site on the same website is never touched by the backups or restores of the blog, and the other way round.

### Your passwords

Passwords are created for you. Open the page of the site and press **Show passwords**: they appear **only once**,
so save them right away, for example in a password manager. After 7 days they are deleted, even if nobody looked at
them. They are never sent by email.

If you lose the WordPress password, use **New password** on the site page, or "Lost your password?" on the login page
of WordPress.

## Updates

Sites created from this menu are updated **automatically**: plugins, themes and minor releases of WordPress. Before
every update a backup is taken. If the site stops working afterwards, the backup is put back by itself.

Sites that were already on the server before, and were found there, start with automatic updates **off**: turn them on
from the page of the site when you are ready.

You can also:

- update now, with the buttons under **Updates** on the site page;
- move to a new major release of WordPress, with its own button. Check first that your plugins and theme support it;
- turn automatic updates on or off for one site, for example off if it must never change without you.

## Backups

**Create backup** saves the files and the database of the site. The last 3 are kept and count towards your disk
space. **Restore** brings the site back to that moment: everything that changed since then is lost.

These backups are meant for going back after a change that went wrong. They do not replace the backups of your
provider.

## A copy of your site

- **Clone**: a copy of the site at a new address, for example to start a new site from an existing one. The copy is
  public and asks search engines to stay away. It has the same users and passwords as the original.
- **Create staging**: a test copy at `staging.yourdomain`, to try a new plugin or a new design without touching the
  real site. It sends no email, so customers of a shop receive nothing from it. It is not protected by a password:
  anyone who knows the address can open it.

The page of the staging has two buttons:

- **Update from production**: the staging becomes a fresh copy of the real site again;
- **Push to production**: the real site becomes a copy of the staging. **Everything** is replaced, also orders,
  comments, new users and form entries received in the meantime. Use it only if nothing important happened on the
  real site since you made the copy.

## Tools

- **Maintenance mode**: visitors see a "maintenance" page while you work; you keep seeing the site when you are logged
  in. It stays on until you turn it off.
- **Log in to wp-admin**: opens the dashboard with a temporary administrator, without a password. It disappears by
  itself after you are done (2 hours at most). Use it when you forgot your password or the site will not let you in.
- **Administrators**: the administrators of WordPress, each with **New password**. The new password appears once, and
  that administrator is logged out everywhere.
- **Debug**: when the site shows an error, turn on debug for 24 hours, repeat what causes the error, then **Show the
  last 200 lines**. Visitors never see the errors. Send the lines to whoever develops your site.
- **Plugins** and **Themes**: activate, deactivate or remove them. If the site stops working after the change, it is
  undone.
- **WordPress cron**: how scheduled tasks of WordPress run (newsletters, scheduled posts). Leave it as it is unless your
  developer asks for **real cron**.

## Security

- **Known vulnerabilities**: if a plugin, theme or WordPress of your site has a known security problem, it is listed
  here. When a fixed version exists, the site is updated soon by itself if automatic updates are on. When none exists,
  consider deactivating that plugin.
- **File permissions**: files that anyone on the server could change. **Fix the permissions** corrects them.
- **Hardening**: protections you can turn on or off. New sites have three of them on already. **No XML-RPC** also stops Jetpack
  and the WordPress app for phones: leave it off if you use them.
- **SSH keys for SFTP**: if you log in with an SSH key, paste its public part here (the content of the `.pub` file),
  one key per line. Your password keeps working.

## Recent activity

The bottom of the site page lists the last 10 things done on the site: by whom, when, and how it went.

## Deleting a site

**Delete the site** on the site page removes the site for good: its files, its database and its users. The page lists
what will go. Type the domain to confirm. There is **no backup** of it afterwards, so download what you want to keep
first. A site with a staging can be deleted only after its staging.

## When something does not work

The site page explains what went wrong in plain words. If it is not clear, or a site shows **error**, contact your
provider and tell them the domain and the message on the page.
