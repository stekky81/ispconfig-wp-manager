# Installation

> [!WARNING]
> Pre-release: install only on a test environment. See the warning in the [README](../README.md).

## 1. Remote user

In ISPConfig: **System → Remote Users → Add**. Give it a strong password and enable only:

- Client functions (read)
- Server functions (read)
- Sites: Website, Database, Database user, Shell user, FTP user

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

This installs the server plugin and the worker cron. WP-CLI must be in `/usr/local/bin/wp`.

## 4. Database tables

The installer creates the tables in `dbispconfig` on the master. In a multiserver setup it also
creates them on each node, because ISPConfig replicates datalog records into the node's local copy.

## Uninstall

```bash
sudo ./install/install.sh --uninstall
```

Removes symlinks and cron. Tables and created websites are **not** deleted.
