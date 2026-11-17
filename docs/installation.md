# Installation

> [!WARNING]
> Pre-release: install only on a test environment. See the warning in the [README](../README.md).

## 1. Remote user

In ISPConfig: **System → Remote Users → Add**. Give it a strong password and enable only:

- Sites Domain functions
- Sites database functions
- Sites Shell-User functions
- Sites FTP-User functions
- Sites cron functions

Restrict "Remote access" to the master's own IP.

## 2. On the master

```bash
git clone https://github.com/stekky81/ispconfig-wpstation /opt/ispconfig-wpstation
cd /opt/ispconfig-wpstation
sudo ./install/install.sh --role=master          # add --role=node too on a single-server setup
sudo nano /opt/ispconfig-wpstation/config.php   # remote user, defaults; keep dry_run = true at first
```

The installer creates `config.php` from `config/config.example.php` only if it does not exist yet.

The installer prints the shared key path. Copy that key file to every node (same path, root only).

Enable the module for your admin user: **System → CP Users → edit → Modules → wpstation**.

## 3. On every web node (including the master, if it hosts sites)

```bash
git clone https://github.com/stekky81/ispconfig-wpstation /opt/ispconfig-wpstation
cd /opt/ispconfig-wpstation
sudo ./install/install.sh --role=node
```

This installs the server module and plugin and the worker cron. WP-CLI must be in `/usr/local/bin/wp`.

On a node that is not the master:

- copy the shared key from the master first: `/etc/ispconfig-wpstation/secret.key`, folder `0700`, file `0600`,
  owner root;
- the installer creates the node's own `config.php` (`0600 root:root`) with `dry_run = true`: each node decides for
  itself, so set `dry_run` there too. The Remote API account is only needed on the master;
- then run the master installer once more: it grants the module tables on the master to the database account of
  every node (`ispcsrvN`). Do it again whenever a node is added.

Sites with SFTP access log in with a password, so `sshd` on the node needs `PasswordAuthentication yes`.
Ubuntu cloud images disable it (`/etc/ssh/sshd_config.d/60-cloudimg-settings.conf`).

## Before turning off dry-run

`dry_run = true` stops every change. Turning it off starts the worker for real, and that includes
**automatic updates of the sites this module created**. Sites it finds on the node start with automatic updates off,
until someone turns them on from the site page. To look at a node first without any automatic update, set this in
`config.php` before changing `dry_run`:

```php
'updates' => ['auto' => 'n'],
```

They can be changed later in **WordPress → Settings** and for each site on its page.

If you rely on the jail to contain your clients, read
[WP-CLI runs outside the jail](security.md#wp-cli-runs-outside-the-jail).

Backups go to the `tmp` folder of each site and count towards its quota. If ISPConfig uses backup mode `userzip` with a
tar format, its own backups will include them: see [architecture.md](architecture.md#backups).

## Known vulnerabilities (optional)

The check of known vulnerabilities uses the free Vulnerability Data Feed of Wordfence Intelligence (Defiant Inc.).
Its [terms](https://www.wordfence.com/wordfence-intelligence-terms-and-conditions/) allow commercial use, but every
installation needs the API key of its own wordfence.com account, which must not be shared:

1. Create a free account on wordfence.com, then Account → Integrations → create an API key. It is shown once.
2. Put it in `/opt/ispconfig-wpstation/config.php` of one node or more:
   `'vulnerabilities' => ['wordfence_key' => '...', 'feed' => 'production'],`
3. Within 6 hours a node downloads the feed (about 90 MB or more) and stores it in the master database; then once a
   day. Wordfence limits the requests of a key without publishing the limits (on 2026-11-16 a second request a few
   minutes after the first was refused): the module tries at most once every 6 hours. Settings shows the date of the
   last download.

Without a key nothing is checked and nothing is sent to Wordfence.

## 4. Database tables

The installer creates the tables in `dbispconfig` on the master. In a multiserver setup it also
creates them on each node, because ISPConfig replicates datalog records into the node's local copy.

## After an ISPConfig update

The module stays: the updater of ISPConfig copies its own files over the old ones and leaves other folders alone,
and it keeps our module and plugin enabled (checked with an update of 3.3.2p1 over itself). Running our installer
again afterwards does no harm.

## Uninstall

```bash
sudo ./install/install.sh --uninstall
```

Removes the module, the server plugin and the cron job. Tables, keys, backups and websites are **not** deleted.
