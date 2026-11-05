#!/usr/bin/env bash
# SPDX-License-Identifier: BSD-3-Clause
#
# Local test environment on macOS (or Linux) with Multipass: https://multipass.run
#
#   ./dev/test-env.sh create     # master VM with ISPConfig + project copied, snapshot "clean"
#   ./dev/test-env.sh sync       # copy the current project into the VMs (after every change)
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
APP_DIR=/opt/ispconfig-wp-manager

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
    # cloud-init (manage_etc_hosts) rebuilds /etc/hosts at every boot from this template.
    tmpl=/etc/cloud/templates/hosts.debian.tmpl
    if [[ -f $tmpl ]]; then
        grep -v '# ispconfig-wp-manager$' "$tmpl" > "$tmpl.new"
        echo "$ip $fqdn $name # ispconfig-wp-manager" >> "$tmpl.new"
        cat "$tmpl.new" > "$tmpl" && rm -f "$tmpl.new"
    fi
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
    if exists "$MASTER" && ispconfig_installed "$MASTER"; then
        # Resume an interrupted run instead of reinstalling.
        info "$MASTER already has ISPConfig: resuming with the remaining steps"
        set_fqdn "$MASTER"
    else
        exists "$MASTER" && die "$MASTER exists without ISPConfig: run destroy first"
        install_master
    fi

    multipass exec "$MASTER" -- test -x /usr/local/bin/wp || install_wpcli "$MASTER"
    multipass info "$MASTER.$SNAPSHOT" >/dev/null 2>&1 || snapshot "$MASTER"
    sync_project "$MASTER"

    cmd_info
    echo
    echo "ISPConfig admin password (saved in the VM at /root/ispconfig-install.log):"
    multipass exec "$MASTER" -- sudo grep -ai "admin password" /root/ispconfig-install.log || true
}

install_master() {
    launch "$MASTER"

    info "Installing ISPConfig on $MASTER (10-20 minutes)"
    # The autoinstaller needs a terminal: `script` provides one even when our own
    # output is piped (e.g. into tee) and also keeps a full log inside the VM.
    multipass exec "$MASTER" -- sudo script -qefc \
        "wget -qO - https://get.ispconfig.org | sh -s -- $(ai_flags)" \
        /root/ispconfig-install.log \
        || die "ISPConfig installation failed: see the output above (log in the VM: /root/ispconfig-install.log)"
    verify_ispconfig "$MASTER"
    # The clean snapshot (taken by cmd_create) has no project files:
    # sync copies them after every create and reset.
}

# /usr/local/ispconfig is readable by root only.
ispconfig_installed() {
    multipass exec "$1" -- sudo test -f /usr/local/ispconfig/server/lib/config.inc.php
}

# The autoinstaller can stop without a non-zero exit code: check the result itself.
verify_ispconfig() {
    local name=$1
    if multipass exec "$name" -- sudo grep -qaE '\[ERROR\]|cannot open /dev' /root/ispconfig-install.log \
        || ! ispconfig_installed "$name"; then
        die "ISPConfig is not installed on $name: see the output above (log in the VM: /root/ispconfig-install.log)"
    fi
    info "ISPConfig installed on $name"
}

# ISPConfig's autoinstaller removes snapd, so multipass mount (sshfs) is not available.
# Copy the project instead: closer to a real install anyway. config.php in the VM is kept.
sync_project() {
    local name=$1 archive
    archive="${TMPDIR:-/tmp}/wpm-sync-$$.tgz"
    info "Copying the project to $name:$APP_DIR"
    COPYFILE_DISABLE=1 tar -czf "$archive" -C "$PROJECT_DIR" \
        --exclude ./.git --exclude ./vendor --exclude ./dev/logs --exclude ./config.php .
    multipass transfer "$archive" "$name:/tmp/wpm-sync.tgz"
    rm -f "$archive"
    multipass exec "$name" -- sudo bash -s -- "$APP_DIR" <<'EOS'
set -euo pipefail
dst=$1 new="$1.new"
rm -rf "$new" && mkdir -p "$new"
tar -xzf /tmp/wpm-sync.tgz -C "$new" --no-same-owner --warning=no-unknown-keyword
chown -R root:root "$new"
if [[ -f $dst/config.php ]]; then cp -p "$dst/config.php" "$new/config.php"; fi
rm -rf "$dst.old"
if [[ -d $dst ]]; then mv "$dst" "$dst.old"; fi
mv "$new" "$dst"
rm -rf "$dst.old" /tmp/wpm-sync.tgz
EOS
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
        sync_project "$vm"
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
    snapshot-slave) snapshot "$SLAVE"; sync_project "$SLAVE" ;;
    sync)
        for vm in "$MASTER" "$SLAVE"; do if exists "$vm"; then sync_project "$vm"; fi; done
        ;;
    reset) cmd_reset ;;
    shell) multipass shell "$MASTER" ;;
    info) cmd_info ;;
    destroy)
        for vm in "$MASTER" "$SLAVE"; do if exists "$vm"; then multipass delete --purge "$vm"; fi; done
        info "VMs deleted"
        ;;
    *) sed -n '3,15p' "$0"; exit 2 ;;
esac
