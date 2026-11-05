#!/usr/bin/env bash
# SPDX-License-Identifier: BSD-3-Clause
#
# Local test environment on macOS (or Linux) with Multipass: https://multipass.run
#
#   ./dev/test-env.sh create     # master VM with ISPConfig + project mounted, snapshot "clean"
#   ./dev/test-env.sh slave      # second VM for multiserver tests (ISPConfig setup is interactive)
#   ./dev/test-env.sh reset      # restore every VM to its "clean" snapshot
#   ./dev/test-env.sh shell      # open a root shell on the master
#   ./dev/test-env.sh info       # IPs, panel URL, /etc/hosts lines
#   ./dev/test-env.sh destroy    # delete the VMs
#
# Settings (environment variables):
#   UBUNTU=24.04   WEB=apache|nginx   CPUS=2   MEMORY=3G   DISK=20G
#
# Use the same Ubuntu release and web server as your production nodes.

set -euo pipefail

UBUNTU=${UBUNTU:-24.04}
WEB=${WEB:-apache}
CPUS=${CPUS:-2}
MEMORY=${MEMORY:-3G}
DISK=${DISK:-20G}

MASTER=ispc-master
SLAVE=ispc-slave
SNAPSHOT=clean
PROJECT_DIR=$(cd "$(dirname "$0")/.." && pwd)
MOUNT_POINT=/opt/ispconfig-wp-manager

die() { echo "ERROR: $*" >&2; exit 1; }
info() { echo "==> $*"; }

command -v multipass >/dev/null || die "Multipass not found: brew install --cask multipass"

# Multipass < 1.17 on macOS ships qemu-img 8.2.1, which crashes (SIGSEGV in img_amend) on launch.
MP_VERSION=$(multipass version 2>/dev/null | awk '/^multipass /{print $2}' | cut -d+ -f1)
if [[ $(uname) == Darwin && $MP_VERSION =~ ^([0-9]+)\.([0-9]+) ]]; then
    if (( BASH_REMATCH[1] < 1 || (BASH_REMATCH[1] == 1 && BASH_REMATCH[2] < 17) )); then
        die "Multipass $MP_VERSION is too old on macOS: install 1.17.0 or later"
    fi
fi

exists() { multipass info "$1" >/dev/null 2>&1; }

ai_flags() {
    local flags=(--use-php=system --no-mail --no-dns --no-firewall --no-quota --i-know-what-i-am-doing)
    [[ $WEB == nginx ]] && flags+=(--use-nginx)
    echo "${flags[@]}"
}

launch() {
    local name=$1
    exists "$name" && die "$name already exists (use reset or destroy)"
    info "Launching $name (Ubuntu $UBUNTU, $CPUS CPU, $MEMORY RAM, $DISK disk)"
    multipass launch "$UBUNTU" --name "$name" --cpus "$CPUS" --memory "$MEMORY" --disk "$DISK"
    multipass exec "$name" -- sudo apt-get update -q
    multipass exec "$name" -- sudo apt-get install -y -q wget curl
}

install_wpcli() {
    local name=$1
    info "Installing WP-CLI on $name"
    multipass exec "$name" -- sudo bash -c '
        curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar &&
        chmod 0755 /usr/local/bin/wp && /usr/local/bin/wp --info --allow-root | head -3'
}

snapshot() {
    local name=$1
    info "Taking snapshot '$SNAPSHOT' of $name"
    multipass stop "$name"
    multipass snapshot "$name" --name "$SNAPSHOT"
    multipass start "$name"
}

cmd_create() {
    launch "$MASTER"

    info "Installing ISPConfig on $MASTER (10-20 minutes)"
    # shellcheck disable=SC2046  # flags are intentionally split
    multipass exec "$MASTER" -- sudo bash -c \
        "wget -qO - https://get.ispconfig.org | sh -s -- $(ai_flags) 2>&1 | tee /root/ispconfig-install.log"

    install_wpcli "$MASTER"

    info "Mounting the project at $MOUNT_POINT"
    multipass mount "$PROJECT_DIR" "$MASTER:$MOUNT_POINT"

    snapshot "$MASTER"
    # Mounts survive restarts but are not part of the snapshot: re-check after start.
    multipass info "$MASTER" | grep -q "$MOUNT_POINT" || multipass mount "$PROJECT_DIR" "$MASTER:$MOUNT_POINT"

    cmd_info
    echo
    echo "ISPConfig admin password (saved in the VM at /root/ispconfig-install.log):"
    multipass exec "$MASTER" -- sudo grep -i "admin password" /root/ispconfig-install.log || true
}

cmd_slave() {
    exists "$MASTER" || die "create the master first"
    launch "$SLAVE"
    install_wpcli "$SLAVE"
    multipass mount "$PROJECT_DIR" "$SLAVE:$MOUNT_POINT"
    cat <<EOF

The slave must be joined to the master interactively (ISPConfig "expert mode").
1. multipass shell $SLAVE
2. sudo bash -c "wget -qO - https://get.ispconfig.org | sh -s -- $(ai_flags | sed 's/--i-know-what-i-am-doing//') --interactive"
   Master hostname/IP: $(multipass info "$MASTER" | awk '/IPv4/ {print $2; exit}')
3. Then run: ./dev/test-env.sh snapshot-slave
Guide: https://www.howtoforge.com/ispconfig-autoinstall-debian-ubuntu/
EOF
}

cmd_reset() {
    for vm in "$MASTER" "$SLAVE"; do
        exists "$vm" || continue
        info "Restoring $vm to '$SNAPSHOT'"
        multipass stop "$vm"
        multipass restore --destructive "$vm.$SNAPSHOT"
        multipass start "$vm"
        multipass info "$vm" | grep -q "$MOUNT_POINT" || multipass mount "$PROJECT_DIR" "$vm:$MOUNT_POINT"
    done
}

cmd_info() {
    for vm in "$MASTER" "$SLAVE"; do
        exists "$vm" || continue
        local ip
        ip=$(multipass info "$vm" | awk '/IPv4/ {print $2; exit}')
        echo "$vm: $ip"
        [[ $vm == "$MASTER" ]] && echo "  Panel: https://$ip:8080 (self-signed certificate)"
    done
    echo
    echo "Test domains: add to /etc/hosts on the Mac, e.g."
    echo "  <master-ip>  sito1.ispc.test www.sito1.ispc.test"
}

case "${1:-}" in
    create) cmd_create ;;
    slave) cmd_slave ;;
    snapshot-slave) snapshot "$SLAVE" ;;
    reset) cmd_reset ;;
    shell) multipass shell "$MASTER" ;;
    info) cmd_info ;;
    destroy)
        for vm in "$MASTER" "$SLAVE"; do exists "$vm" && multipass delete --purge "$vm"; done
        info "VMs deleted"
        ;;
    *) sed -n '3,15p' "$0"; exit 2 ;;
esac
