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
#   UBUNTU=24.04   WEB=apache|nginx   CPUS=2   MEMORY=3G   DISK=20G   TEST_DOMAIN=ispc.test
#
# Use the same Ubuntu release and web server as your production nodes.

set -euo pipefail

UBUNTU=${UBUNTU:-24.04}
WEB=${WEB:-apache}
CPUS=${CPUS:-2}
MEMORY=${MEMORY:-3G}
DISK=${DISK:-20G}
TEST_DOMAIN=${TEST_DOMAIN:-ispc.test}

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
    set_fqdn "$name"
}

# ISPConfig requires a fully qualified host name that resolves to the VM's own IP.
set_fqdn() {
    local name=$1 fqdn="$1.$TEST_DOMAIN"
    info "Setting host name $fqdn"
    multipass exec "$name" -- sudo bash -s -- "$name" "$fqdn" <<'EOS'
set -euo pipefail
name=$1 fqdn=$2 hosts=${HOSTS_FILE:-/etc/hosts}
ip=$(hostname -I | awk '{print $1}')
[[ -n $ip ]] || { echo "No IP address found" >&2; exit 1; }
[[ -n ${HOSTS_FILE:-} ]] || {
    echo 'preserve_hostname: true' > /etc/cloud/cloud.cfg.d/99-ispconfig-fqdn.cfg
    hostnamectl set-hostname "$fqdn"
}
# Drop any line that mentions the short or full name, then map the VM IP to the FQDN.
awk -v n="$name" -v f="$fqdn" '{ for (i = 2; i <= NF; i++) if ($i == n || $i == f) next } 1' "$hosts" > "$hosts.new"
echo "$ip $fqdn $name" >> "$hosts.new"
cat "$hosts.new" > "$hosts" && rm -f "$hosts.new"
[[ -n ${HOSTS_FILE:-} ]] || [[ $(hostname -f) == "$fqdn" ]]
EOS
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
        "set -o pipefail; wget -qO - https://get.ispconfig.org | sh -s -- $(ai_flags) 2>&1 | tee /root/ispconfig-install.log" \
        || die "ISPConfig installation failed: see the output above (log in the VM: /root/ispconfig-install.log)"
    # The autoinstaller may exit 0 after logging an error: check for it explicitly.
    if multipass exec "$MASTER" -- sudo grep -q '^\[ERROR\]' /root/ispconfig-install.log; then
        die "ISPConfig installation reported errors: see the output above"
    fi

    install_wpcli "$MASTER"

    # Snapshot first, mount afterwards: the clean snapshot has no mount,
    # and reset re-creates the mount after each restore.
    snapshot "$MASTER"
    ensure_mount "$MASTER"

    cmd_info
    echo
    echo "ISPConfig admin password (saved in the VM at /root/ispconfig-install.log):"
    multipass exec "$MASTER" -- sudo grep -i "admin password" /root/ispconfig-install.log || true
}

ensure_mount() {
    local name=$1
    if ! multipass info "$name" | grep -q "$MOUNT_POINT"; then
        info "Mounting the project at $MOUNT_POINT in $name (first time: a few minutes)"
        multipass mount "$PROJECT_DIR" "$name:$MOUNT_POINT"
    fi
}

cmd_slave() {
    exists "$MASTER" || die "create the master first"
    launch "$SLAVE"
    install_wpcli "$SLAVE"
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
        ensure_mount "$vm"
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
    snapshot-slave) snapshot "$SLAVE"; ensure_mount "$SLAVE" ;;
    reset) cmd_reset ;;
    shell) multipass shell "$MASTER" ;;
    info) cmd_info ;;
    destroy)
        for vm in "$MASTER" "$SLAVE"; do exists "$vm" && multipass delete --purge "$vm"; done
        info "VMs deleted"
        ;;
    *) sed -n '3,15p' "$0"; exit 2 ;;
esac
