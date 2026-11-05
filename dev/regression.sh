#!/usr/bin/env bash
# SPDX-License-Identifier: BSD-3-Clause
#
# Checks that a change leaves the existing sites alone. Run it in the test VM, as root:
#   dev/regression.sh snapshot   # record files, databases and vhosts of every site
#   ...do the work: new site, installer, scan...
#   dev/regression.sh compare    # fails if anything recorded has changed
# Sites created after the snapshot are ignored. Read-only, except for the snapshot file.
# WordPress rewrites its transients and its cron option by itself whenever it loads, so they are left out.

set -euo pipefail

SNAPSHOT=/root/.wpm-regression

# One "<checksum> <name>" line for each web root, client database and vhost.
state() {
    local dir table db
    for dir in /var/www/clients/client*/web*/web; do
        [[ -d $dir ]] || continue
        echo "$(find "$dir" -type f -print0 | sort -z | xargs -0 -r sha1sum | sha1sum | cut -d' ' -f1) files:$dir"
    done
    while read -r db; do
        while read -r table; do
            if [[ $table == *_options ]]; then
                echo "$(mysql -N "$db" -e "SELECT option_name, option_value FROM \`$table\` WHERE option_name NOT LIKE '%\\_transient\\_%' AND option_name <> 'cron' ORDER BY option_name" | sha1sum | cut -d' ' -f1) table:$db.$table"
            else
                echo "$(mysql -N "$db" -e "CHECKSUM TABLE \`$table\` EXTENDED" | sha1sum | cut -d' ' -f1) table:$db.$table"
            fi
        done < <(mysql -N -e "SELECT table_name FROM information_schema.tables WHERE table_schema = '$db'")
    done < <(mysql -N dbispconfig -e 'SELECT database_name FROM web_database')
    for dir in /etc/apache2/sites-available/*.vhost; do
        [[ -f $dir ]] && echo "$(sha1sum < "$dir" | cut -d' ' -f1) vhost:$dir"
    done
}

case "${1:-}" in
    snapshot)
        state > "$SNAPSHOT"
        echo "Recorded $(wc -l < "$SNAPSHOT") items"
        ;;
    compare)
        changed=$(state | grep -Fxv -f - "$SNAPSHOT" || true)
        if [[ -n $changed ]]; then
            echo "CHANGED OR MISSING:"
            echo "$changed"
            exit 1
        fi
        echo "OK: $(wc -l < "$SNAPSHOT") items unchanged"
        ;;
    *) sed -n '4,8p' "$0"; exit 2 ;;
esac
