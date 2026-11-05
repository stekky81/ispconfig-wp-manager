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
git clone https://github.com/stekky81/ispconfig-wp-manager /opt/ispconfig-wp-manager
cd /opt/ispconfig-wp-manager
sudo ./install/install.sh --role=master          # add --role=node too on a single-server setup
sudo nano /opt/ispconfig-wp-manager/config.php   # remote user, defaults; keep dry_run = true at first
```

The installer creates `config.php` from `config/config.example.php` only if it does not exist yet.

The installer prints the shared key path. Copy that key file to every node (same path, root only).

Enable the module for your admin user: **System → CP Users → edit → Modules → wpmanager**.

## 3. On every web node (including the master, if it hosts sites)

```bash
git clone https://github.com/stekky81/ispconfig-wp-manager /opt/ispconfig-wp-manager
cd /opt/ispconfig-wp-manager
sudo ./install/install.sh --role=node
```

This installs the server module and plugin and the worker cron. WP-CLI must be in `/usr/local/bin/wp`.

Sites with SFTP access log in with a password, so `sshd` on the node needs `PasswordAuthentication yes`.
Ubuntu cloud images disable it (`/etc/ssh/sshd_config.d/60-cloudimg-settings.conf`).

## Before turning off dry-run

`dry_run = true` stops every change. Turning it off starts the worker for real, and that includes
**automatic updates of every site on the node**, also the ones this module did not create. To look at a node
first, set this in `config.php` before changing `dry_run`:

```php
'updates' => ['auto' => 'n'],
```

They can be changed later in **WordPress → Settings** and for each site on its page.

Backups go to the `tmp` folder of each site and count towards its quota. If ISPConfig uses backup mode `userzip` with a
tar format, its own backups will include them: see [architecture.md](architecture.md#backups).

## 4. Database tables

The installer creates the tables in `dbispconfig` on the master. In a multiserver setup it also
creates them on each node, because ISPConfig replicates datalog records into the node's local copy.

## Uninstall

```bash
sudo ./install/install.sh --uninstall
```

Removes the module, the server plugin and the cron job. Tables, keys, backups and websites are **not** deleted.
