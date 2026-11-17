#!/usr/bin/env bash
# SPDX-License-Identifier: BSD-3-Clause
#
# Environment for the integration tests of lib/ (tests/Integration): real users, folders, MariaDB databases,
# WP-CLI and WordPress, without ISPConfig. Used by the CI and by the test VM. Run as root.
#
#   dev/integration.sh setup      # two sites of one client under $WPSTATION_IT_BASE, a database for a site in a folder
#                                 # and one for the module tables
#   dev/integration.sh teardown   # removes all of it
#
# Then: set -a; . /etc/wpstation-it.env; set +a; vendor/bin/phpunit --testsuite integration
#
# Settings, from the environment:
#   WPSTATION_IT_BASE       base of the web roots (default /var/www/clients-it); never an existing ISPConfig tree
#   WPSTATION_IT_DB_HOST    host the sites use for MariaDB (default localhost)
#   MYSQL_HOST, MYSQL_PWD: how this script reaches MariaDB as root (default: the local socket)
#   WPSTATION_IT_WP_CLI     WP-CLI (default /usr/local/bin/wp)

set -euo pipefail

BASE=${WPSTATION_IT_BASE:-/var/www/clients-it}
DB_HOST=${WPSTATION_IT_DB_HOST:-localhost}
WP=${WPSTATION_IT_WP_CLI:-/usr/local/bin/wp}
ENV_FILE=/etc/wpstation-it.env
GROUP=client901
SITES=(901 902)

die() { echo "ERROR: $*" >&2; exit 1; }
info() { echo "==> $*"; }
sql() { mysql ${MYSQL_HOST:+-h "$MYSQL_HOST"} -u root -N -e "$1"; }

[[ $(id -u) -eq 0 ]] || die "run as root"
[[ $BASE == /var/www/clients-it* || $BASE == /tmp/* ]] || die "WPSTATION_IT_BASE must be under /var/www/clients-it or /tmp"

setup() {
    command -v "$WP" >/dev/null || die "WP-CLI not found at $WP"
    getent group "$GROUP" >/dev/null || groupadd "$GROUP"
    install -d -m 0755 "$BASE" "$BASE/$GROUP"
    local n user root password
    : > "$ENV_FILE.new"
    chmod 0600 "$ENV_FILE.new"
    for n in "${SITES[@]}"; do
        user=web$n
        root=$BASE/$GROUP/$user
        id "$user" >/dev/null 2>&1 || useradd -M -d "$root" -s /usr/sbin/nologin -g "$GROUP" "$user"
        # As ISPConfig: the site folder belongs to root, what is inside to the site user.
        install -d -m 0755 -o root -g root "$root"
        install -d -m 0755 -o "$user" -g "$GROUP" "$root/web" "$root/tmp" "$root/private"
        password=$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')
        sql "CREATE DATABASE IF NOT EXISTS wpsit_$user CHARACTER SET utf8mb4;
             CREATE USER IF NOT EXISTS 'wpsit_$user'@'%' IDENTIFIED BY '$password';
             ALTER USER 'wpsit_$user'@'%' IDENTIFIED BY '$password';
             CREATE USER IF NOT EXISTS 'wpsit_$user'@'localhost' IDENTIFIED BY '$password';
             ALTER USER 'wpsit_$user'@'localhost' IDENTIFIED BY '$password';
             GRANT ALL ON wpsit_$user.* TO 'wpsit_$user'@'%';
             GRANT ALL ON wpsit_$user.* TO 'wpsit_$user'@'localhost';"
        printf 'WPSTATION_IT_DB_PASSWORD_%s=%s\n' "$n" "$password" >> "$ENV_FILE.new"
    done

    # A second WordPress in the folder web901/web/blog, installed by the tests: its own database.
    password=$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')
    sql "CREATE DATABASE IF NOT EXISTS wpsit_blog901 CHARACTER SET utf8mb4;
         CREATE USER IF NOT EXISTS 'wpsit_blog901'@'%' IDENTIFIED BY '$password';
         ALTER USER 'wpsit_blog901'@'%' IDENTIFIED BY '$password';
         CREATE USER IF NOT EXISTS 'wpsit_blog901'@'localhost' IDENTIFIED BY '$password';
         ALTER USER 'wpsit_blog901'@'localhost' IDENTIFIED BY '$password';
         GRANT ALL ON wpsit_blog901.* TO 'wpsit_blog901'@'%';
         GRANT ALL ON wpsit_blog901.* TO 'wpsit_blog901'@'localhost';"
    printf 'WPSTATION_IT_DB_PASSWORD_BLOG=%s\n' "$password" >> "$ENV_FILE.new"

    info "WordPress in web901"
    local web=$BASE/$GROUP/web901/web
    if [[ ! -f $web/wp-config.php ]]; then
        (cd /tmp && sudo -u web901 -H "$WP" core download --path="$web" --quiet)
        local db_password
        db_password=$(grep WPSTATION_IT_DB_PASSWORD_901 "$ENV_FILE.new" | cut -d= -f2)
        (cd /tmp && printf '%s\n' "$db_password" | sudo -u web901 -H "$WP" config create --path="$web" \
            --dbname=wpsit_web901 --dbuser=wpsit_web901 --dbhost="$DB_HOST" --prompt=dbpass --quiet)
        (cd /tmp && printf '%s\n' "$(head -c 18 /dev/urandom | base64)" | sudo -u web901 -H "$WP" core install --path="$web" \
            --url=http://it901.test --title='Integration' --admin_user=itadmin --admin_email=it@example.com \
            --skip-email --prompt=admin_password --quiet)
    fi

    info "Tables of the module"
    local module_password
    module_password=$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')
    sql "CREATE DATABASE IF NOT EXISTS wpsit_module CHARACTER SET utf8mb4;
         CREATE USER IF NOT EXISTS 'wpsit_module'@'%' IDENTIFIED BY '$module_password';
         ALTER USER 'wpsit_module'@'%' IDENTIFIED BY '$module_password';
         CREATE USER IF NOT EXISTS 'wpsit_module'@'localhost' IDENTIFIED BY '$module_password';
         ALTER USER 'wpsit_module'@'localhost' IDENTIFIED BY '$module_password';
         GRANT ALL ON wpsit_module.* TO 'wpsit_module'@'%';
         GRANT ALL ON wpsit_module.* TO 'wpsit_module'@'localhost';"
    mysql ${MYSQL_HOST:+-h "$MYSQL_HOST"} -u root wpsit_module < "$(dirname "$0")/../install/sql/wpstation.sql"

    {
        printf 'WPSTATION_IT_BASE=%s\nWPSTATION_IT_DB_HOST=%s\nWPSTATION_IT_WP_CLI=%s\nWPSTATION_IT_PHP=%s\n' "$BASE" "$DB_HOST" "$WP" "$(command -v php)"
        printf 'WPSTATION_TEST_DB_HOST=%s\nWPSTATION_TEST_DB_USER=wpsit_module\nWPSTATION_TEST_DB_PASSWORD=%s\nWPSTATION_TEST_DB_NAME=wpsit_module\n' \
            "${MYSQL_HOST:-localhost}" "$module_password"
    } >> "$ENV_FILE.new"
    mv "$ENV_FILE.new" "$ENV_FILE"
    info "Ready: $ENV_FILE"
}

teardown() {
    local n
    for n in "${SITES[@]}"; do
        sql "DROP DATABASE IF EXISTS wpsit_web$n; DROP USER IF EXISTS 'wpsit_web$n'@'%'; DROP USER IF EXISTS 'wpsit_web$n'@'localhost';"
        id "web$n" >/dev/null 2>&1 && userdel "web$n"
    done
    sql "DROP DATABASE IF EXISTS wpsit_blog901; DROP USER IF EXISTS 'wpsit_blog901'@'%'; DROP USER IF EXISTS 'wpsit_blog901'@'localhost';"
    sql "DROP DATABASE IF EXISTS wpsit_module; DROP USER IF EXISTS 'wpsit_module'@'%'; DROP USER IF EXISTS 'wpsit_module'@'localhost';"
    getent group "$GROUP" >/dev/null && groupdel "$GROUP"
    rm -rf -- "$BASE" "$ENV_FILE"
    info "Removed"
}

case "${1:-}" in
    setup) setup ;;
    teardown) teardown ;;
    *) sed -n '3,17p' "$0"; exit 2 ;;
esac
