#!/usr/bin/env bash
# SPDX-License-Identifier: BSD-3-Clause
#
# Installer for ispconfig-wp-manager. Idempotent: run it again after every ISPConfig update.
#
#   ./install/install.sh --role=master     # panel: module symlink, tables, shared key
#   ./install/install.sh --role=node       # web node: server module and plugin, worker cron, tables
#   ./install/install.sh --role=master --role=node   # single-server setup
#   ./install/install.sh --uninstall       # remove symlinks and cron (keeps tables and sites)

set -euo pipefail

APP_DIR=/opt/ispconfig-wp-manager
ISP_DIR=/usr/local/ispconfig
KEY_DIR=/etc/ispconfig-wp-manager
KEY_FILE=$KEY_DIR/secret.key
SPOOL_DIR=/var/spool/ispconfig-wp-manager
CRON_FILE=/etc/cron.d/ispconfig-wp-manager

MASTER=0
NODE=0
UNINSTALL=0

for arg in "$@"; do
    case "$arg" in
        --role=master) MASTER=1 ;;
        --role=node) NODE=1 ;;
        --uninstall) UNINSTALL=1 ;;
        *) echo "Unknown option: $arg" >&2; exit 2 ;;
    esac
done

die() { echo "ERROR: $*" >&2; exit 1; }
info() { echo "==> $*"; }

[[ $EUID -eq 0 ]] || die "run as root"
[[ -d $ISP_DIR ]] || die "ISPConfig not found in $ISP_DIR"
[[ "$(cd "$(dirname "$0")/.." && pwd)" == "$APP_DIR" ]] || die "clone the repository into $APP_DIR first"

if [[ $UNINSTALL -eq 1 ]]; then
    info "Removing symlinks and cron"
    # Our own module folder only (a copy, or a symlink from older versions).
    rm -rf "${ISP_DIR:?}/interface/web/wpmanager"
    rm -f "$ISP_DIR/server/mods-enabled/wpmanager_module.inc.php"
    rm -f "$ISP_DIR/server/mods-available/wpmanager_module.inc.php"
    rm -f "$ISP_DIR/server/plugins-enabled/wpmanager_plugin.inc.php"
    rm -f "$ISP_DIR/server/plugins-available/wpmanager_plugin.inc.php"
    rm -f "$CRON_FILE"
    info "Done. Tables, spool and key were kept; websites were not touched."
    exit 0
fi

[[ $MASTER -eq 1 || $NODE -eq 1 ]] || die "choose --role=master and/or --role=node"

php -r 'exit(PHP_VERSION_ID >= 80100 && extension_loaded("sodium") ? 0 : 1);' \
    || die "PHP 8.1+ with the sodium extension is required"

# Read DB settings from the ISPConfig config of this machine.
# The ispconfig DB user cannot CREATE tables: use the DB admin account that ISPConfig
# itself uses to create client databases (server/lib/mysql_clientdb.conf, root only).
db_conf() {
    # shellcheck disable=SC2016  # PHP code, $ is not a shell variable
    ISP_DIR="$ISP_DIR" php -r '
        $conf = [];
        require getenv("ISP_DIR") . "/server/lib/config.inc.php";
        require getenv("ISP_DIR") . "/server/lib/mysql_clientdb.conf";
        echo $clientdb_host, "\n", $clientdb_user, "\n", $clientdb_password, "\n",
             $conf["db_database"], "\n", $conf["db_user"], "\n";
    '
}

# Replace $2 with a fresh copy of $1, owned like the reference folder $3.
copy_tree() {
    local src=$1 dst=$2 ref=$3 owner
    owner=$(stat -c %U:%G "$ref")
    rm -rf "$dst.new"
    cp -R "$src" "$dst.new"
    chown -R "$owner" "$dst.new"
    chmod -R u=rwX,g=rX,o= "$dst.new"
    rm -rf "$dst"
    mv "$dst.new" "$dst"
}

# Copy a server module or plugin into ISPConfig and enable it. $1 = mods or plugins, $2 = file name.
install_server_file() {
    local kind=$1 file=$2 dir="$ISP_DIR/server/$1-available"
    rm -f "$dir/$file"
    install -m 0750 -o "$(stat -c %U "$dir")" -g "$(stat -c %G "$dir")" \
        "$APP_DIR/server/$kind-available/$file" "$dir/$file"
    ln -sfn "$dir/$file" "$ISP_DIR/server/$kind-enabled/$file"
}

create_tables() {
    info "Creating tables (if missing)"
    local db=()
    mapfile -t db < <(db_conf)
    [[ ${#db[@]} -eq 5 && -n ${db[1]} && -n ${db[3]} ]] || die "cannot read the ISPConfig database settings"
    local host=${db[0]} admin=${db[1]} pass=${db[2]} name=${db[3]} panel_user=${db[4]}

    MYSQL_PWD="$pass" mysql -h "$host" -u "$admin" "$name" < "$APP_DIR/install/sql/wpmanager.sql"

    # The panel and server.sh access dbispconfig as $panel_user: make sure it can use our table.
    if ! MYSQL_PWD="$pass" mysql -h "$host" -u "$admin" -e \
        "GRANT SELECT, INSERT, UPDATE, DELETE ON \`$name\`.\`wpm_site\` TO '$panel_user'@'localhost'"; then
        echo "WARNING: could not grant access on wpm_site to '$panel_user'@'localhost'; check the grants manually" >&2
    fi
}

if [[ $MASTER -eq 1 ]]; then
    info "Installing interface module"
    # A copy, not a symlink: ISPConfig modules use relative paths (../../lib/...) that
    # PHP would resolve from the symlink target. Ownership follows the stock "sites" module.
    copy_tree "$APP_DIR/interface/web/wpmanager" "$ISP_DIR/interface/web/wpmanager" "$ISP_DIR/interface/web/sites"

    create_tables

    # The panel runs as the ispconfig user: it must be able to traverse the key folder
    # (0710, group ispconfig) without being able to list it.
    if getent group ispconfig >/dev/null; then
        install -d -m 0710 -o root -g ispconfig "$KEY_DIR"
    else
        install -d -m 0700 "$KEY_DIR"
    fi
    if [[ ! -f $KEY_FILE ]]; then
        info "Generating shared key $KEY_FILE"
        (umask 077; APP_DIR="$APP_DIR" php -r 'require getenv("APP_DIR") . "/lib/bootstrap.php"; echo WpManager\Crypto::generateKey(), "\n";' > "$KEY_FILE")
    fi
    chmod 0600 "$KEY_FILE"
    # The panel runs as the ispconfig user and must read the key.
    chown ispconfig:root "$KEY_FILE" 2>/dev/null || true

    if [[ ! -f $APP_DIR/config.php ]]; then
        install -m 0600 "$APP_DIR/config/config.example.php" "$APP_DIR/config.php"
        chown ispconfig:root "$APP_DIR/config.php" 2>/dev/null || true
        info "Created $APP_DIR/config.php (dry_run = true). Edit it before use."
    fi
    info "Copy $KEY_FILE to the same path on every node (mode 0600, owner root)."
    info "Enable the module: System → CP Users → edit user → Modules → wpmanager"
fi

if [[ $NODE -eq 1 ]]; then
    [[ -x /usr/local/bin/wp ]] || echo "WARNING: WP-CLI not found in /usr/local/bin/wp"
    [[ -f $KEY_FILE ]] || echo "WARNING: $KEY_FILE missing: copy it from the master"

    info "Installing server module and plugin"
    install_server_file mods wpmanager_module.inc.php
    install_server_file plugins wpmanager_plugin.inc.php

    # On a slave node the replicated tables live in the local dbispconfig as well.
    [[ $MASTER -eq 1 ]] || create_tables

    install -d -m 0700 "$SPOOL_DIR"

    info "Installing worker cron"
    cat > "$CRON_FILE" <<EOF
# ispconfig-wp-manager worker
* * * * * root /usr/bin/php $APP_DIR/server/bin/wpm-worker.php >> /var/log/ispconfig-wp-manager.log 2>&1
EOF
    chmod 0644 "$CRON_FILE"
fi

info "Done."
