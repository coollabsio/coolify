#!/usr/bin/env bash
# Restore a Coolify instance backup into an offline KVM VM to test database migrations.
#
# Usage:
#   scripts/restore-backup-vm.sh           asks for the inputs below
#   scripts/restore-backup-vm.sh destroy   deletes the VM, its disk, and the Coolify base images
#
# Inputs (asked interactively, so they do not go into the shell history):
#   Backup file  pg_dump custom-format backup (.dmp), optionally gzipped (.gz)
#   APP_KEY      APP_KEY of the instance that created the backup (base64:...), not shown
#   Version      Coolify version or image tag, e.g. 4.3.23 or sha-99c8409 (empty: latest stable)
#
# Flow:
#   1. Base image (once per version, until destroy): a separate builder VM with internet access installs
#      Coolify. It never receives the backup. Its disk is kept as the base image.
#   2. The restore VM boots from a copy of the base image on a host-only network without
#      internet access and without DNS. It never has internet access, so the restored
#      instance cannot reach real servers, notification channels, or Stripe.
#   3. The script confirms that the VM is offline, copies the backup, replaces the database,
#      sets APP_KEY, turns off Horizon and the scheduler, and starts Coolify.
#      Coolify runs its migrations and seeders on start; no queued or scheduled job runs.
#
# Environment overrides:
#   VM_NAME (coolify-restore-test), VM_MEMORY (4096), VM_VCPUS (4), VM_DISK_SIZE (60G),
#   IMAGE_DIR (/var/lib/libvirt/images/coolify-restore-test),
#   NAT_NETWORK (default), OFFLINE_NETWORK (coolify-restore-offline),
#   OFFLINE_SUBNET (192.168.241)

set -euo pipefail

VM_NAME=${VM_NAME:-coolify-restore-test}
BUILDER_NAME=$VM_NAME-builder
VM_MEMORY=${VM_MEMORY:-4096}
VM_VCPUS=${VM_VCPUS:-4}
VM_DISK_SIZE=${VM_DISK_SIZE:-60G}
IMAGE_DIR=${IMAGE_DIR:-/var/lib/libvirt/images/coolify-restore-test}
NAT_NETWORK=${NAT_NETWORK:-default}
OFFLINE_NETWORK=${OFFLINE_NETWORK:-coolify-restore-offline}
OFFLINE_SUBNET=${OFFLINE_SUBNET:-192.168.241}
# Both VMs use the same MAC address: the guest network config matches the NIC by MAC.
VM_MAC=52:54:00:c0:1f:01
UBUNTU_IMAGE_URL=https://cloud-images.ubuntu.com/noble/current/noble-server-cloudimg-amd64.img
UBUNTU_IMAGE="$IMAGE_DIR/ubuntu-noble-amd64.qcow2"
SSH_KEY="$IMAGE_DIR/ssh_key"
VIRSH=(virsh --connect qemu:///system)
SSH_OPTS=(-i "$SSH_KEY" -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR -o ConnectTimeout=5)

log() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
die() {
    printf '\033[1;31mERROR: %s\033[0m\n' "$*" >&2
    exit 1
}

usage() {
    sed -n '2,11p' "$0" | sed 's/^# \{0,1\}//'
    exit 1
}

# delete_vm <name>: stops and deletes a VM with its disk and seed ISO.
delete_vm() {
    if "${VIRSH[@]}" dominfo "$1" >/dev/null 2>&1; then
        "${VIRSH[@]}" destroy "$1" >/dev/null 2>&1 || true
        "${VIRSH[@]}" undefine "$1" --nvram >/dev/null 2>&1 || "${VIRSH[@]}" undefine "$1" >/dev/null
    fi
    rm -f "$IMAGE_DIR/$1.qcow2" "$IMAGE_DIR/$1-seed.iso"
}

vm_ip() {
    "${VIRSH[@]}" domifaddr "$1" --source lease 2>/dev/null |
        awk '$3 == "ipv4" { sub(/\/.*/, "", $4); print $4; exit }'
}

wait_for_ip() {
    local ip=""
    for _ in $(seq 1 90); do
        ip=$(vm_ip "$1")
        [ -n "$ip" ] && break
        sleep 2
    done
    [ -n "$ip" ] || die "VM $1 did not get an IP address from DHCP."
    echo "$ip"
}

# wait_for_ssh <ip>: waits for SSH and for cloud-init to finish.
wait_for_ssh() {
    for _ in $(seq 1 90); do
        if ssh "${SSH_OPTS[@]}" "root@$1" true 2>/dev/null; then
            ssh "${SSH_OPTS[@]}" "root@$1" 'cloud-init status --wait >/dev/null || true'
            return 0
        fi
        sleep 2
    done
    die "SSH to root@$1 does not respond."
}

vm_ssh() { ssh "${SSH_OPTS[@]}" "root@$VM_IP" "$@"; }

ensure_offline_network() {
    if ! "${VIRSH[@]}" net-info "$OFFLINE_NETWORK" >/dev/null 2>&1; then
        log "Creating offline libvirt network $OFFLINE_NETWORK ($OFFLINE_SUBNET.0/24)"
        local xml
        xml=$(mktemp)
        # No <forward> element: libvirt blocks all traffic between this network and other networks.
        # <dns enable='no'/>: the VM cannot resolve host names through the host either.
        cat >"$xml" <<EOF
<network>
  <name>$OFFLINE_NETWORK</name>
  <bridge stp='on' delay='0'/>
  <dns enable='no'/>
  <ip address='$OFFLINE_SUBNET.1' netmask='255.255.255.0'>
    <dhcp><range start='$OFFLINE_SUBNET.10' end='$OFFLINE_SUBNET.200'/></dhcp>
  </ip>
</network>
EOF
        "${VIRSH[@]}" net-define "$xml" >/dev/null
        rm -f "$xml"
        "${VIRSH[@]}" net-autostart "$OFFLINE_NETWORK" >/dev/null
    fi
    if [[ " $("${VIRSH[@]}" net-list --name | xargs) " != *" $OFFLINE_NETWORK "* ]]; then
        "${VIRSH[@]}" net-start "$OFFLINE_NETWORK" >/dev/null
    fi
}

# create_vm <name> <backing-image> <network>: creates a VM with a copy-on-write disk on the image.
create_vm() {
    local name=$1 backing=$2 network=$3 seed_dir
    log "Creating VM $name on network $network (${VM_VCPUS} vCPU, ${VM_MEMORY} MiB RAM)"
    qemu-img create -q -f qcow2 -F qcow2 -b "$backing" "$IMAGE_DIR/$name.qcow2" "$VM_DISK_SIZE"

    seed_dir=$(mktemp -d)
    cat >"$seed_dir/user-data" <<EOF
#cloud-config
hostname: $name
disable_root: false
ssh_pwauth: false
users:
  - name: root
    ssh_authorized_keys:
      - $(cat "$SSH_KEY.pub")
runcmd:
  - sed -i 's/^#\?PermitRootLogin.*/PermitRootLogin prohibit-password/' /etc/ssh/sshd_config
  - systemctl restart ssh
EOF
    printf 'instance-id: %s-%s\nlocal-hostname: %s\n' "$name" "$(date +%s)" "$name" >"$seed_dir/meta-data"
    if command -v xorriso >/dev/null; then
        xorriso -as mkisofs -quiet -V cidata -J -r -o "$IMAGE_DIR/$name-seed.iso" "$seed_dir/user-data" "$seed_dir/meta-data" 2>/dev/null
    elif command -v cloud-localds >/dev/null; then
        cloud-localds "$IMAGE_DIR/$name-seed.iso" "$seed_dir/user-data" "$seed_dir/meta-data"
    else
        genisoimage -quiet -V cidata -J -r -o "$IMAGE_DIR/$name-seed.iso" "$seed_dir/user-data" "$seed_dir/meta-data"
    fi
    rm -rf "$seed_dir"

    virt-install --connect qemu:///system --name "$name" --memory "$VM_MEMORY" --vcpus "$VM_VCPUS" \
        --import --os-variant ubuntu24.04 \
        --disk "path=$IMAGE_DIR/$name.qcow2,format=qcow2,bus=virtio" \
        --disk "path=$IMAGE_DIR/$name-seed.iso,device=cdrom" \
        --network "network=$network,model=virtio,mac=$VM_MAC" \
        --graphics none --noautoconsole >/dev/null
}

# build_base_image <path>: installs Coolify in a builder VM with internet access and keeps its disk.
build_base_image() {
    local base=$1
    if [ ! -f "$UBUNTU_IMAGE" ]; then
        log "Downloading Ubuntu 24.04 cloud image"
        curl -fL --progress-bar -o "$UBUNTU_IMAGE.part" "$UBUNTU_IMAGE_URL"
        mv "$UBUNTU_IMAGE.part" "$UBUNTU_IMAGE"
    fi

    delete_vm "$BUILDER_NAME"
    create_vm "$BUILDER_NAME" "$UBUNTU_IMAGE" "$NAT_NETWORK"
    VM_IP=$(wait_for_ip "$BUILDER_NAME")
    wait_for_ssh "$VM_IP"

    log "Installing Coolify $VERSION in the builder VM (no backup data in this VM)"
    vm_ssh "curl -fsSL https://cdn.coollabs.io/coolify/install.sh | bash -s -- $VERSION"

    vm_ssh 'shutdown -h now' || true
    for _ in $(seq 1 60); do
        [ "$("${VIRSH[@]}" domstate "$BUILDER_NAME")" = "shut off" ] && break
        sleep 2
    done
    [ "$("${VIRSH[@]}" domstate "$BUILDER_NAME")" = "shut off" ] || die "The builder VM did not shut down."
    "${VIRSH[@]}" undefine "$BUILDER_NAME" --nvram >/dev/null 2>&1 || "${VIRSH[@]}" undefine "$BUILDER_NAME" >/dev/null
    mv "$IMAGE_DIR/$BUILDER_NAME.qcow2" "$base"
    rm -f "$IMAGE_DIR/$BUILDER_NAME-seed.iso"
}

# --- main ---------------------------------------------------------------------

[ "${1:-}" = "destroy" ] && {
    delete_vm "$VM_NAME"
    delete_vm "$BUILDER_NAME"
    rm -f "$IMAGE_DIR"/coolify-base-*.qcow2
    echo "Deleted VM $VM_NAME, its disk, and the Coolify base images."
    exit 0
}
[ $# -eq 0 ] || usage
[ -t 0 ] || die "Run this script in an interactive terminal: it asks for its inputs."

read -r -e -p "Backup file (.dmp or .gz): " BACKUP_FILE
BACKUP_FILE=${BACKUP_FILE/#\~/$HOME}
read -r -s -p "APP_KEY of the backed-up instance (input is hidden): " APP_KEY
echo
read -r -p "Coolify version or image tag (empty for latest stable): " VERSION

[ -s "$BACKUP_FILE" ] || die "Backup file '$BACKUP_FILE' does not exist or is empty."
[[ "$APP_KEY" == base64:* ]] || die "APP_KEY must start with 'base64:'."
for cmd in virsh virt-install qemu-img ssh scp ssh-keygen curl jq; do
    command -v "$cmd" >/dev/null || die "Missing command: $cmd"
done
[ -e /dev/kvm ] || die "/dev/kvm is not available."

if [ -z "$VERSION" ]; then
    VERSION=$(curl -fsSL https://cdn.coollabs.io/coolify/versions.json | jq -r '.coolify.v4.version')
    echo "Latest stable version: $VERSION"
fi
[[ "$VERSION" =~ ^[A-Za-z0-9._-]+$ ]] || die "Version '$VERSION' contains characters that are not allowed."
BASE_IMAGE="$IMAGE_DIR/coolify-base-$VERSION.qcow2"

# Other VMs and containers can use most of the host memory. A VM that does not fit
# makes the host swap until it stops responding, so stop before it starts.
# The previous VM is deleted below, so its memory counts as available.
OLD_VM_KIB=$("${VIRSH[@]}" dominfo "$VM_NAME" 2>/dev/null | awk '/^State:/ { running = ($2 == "running") } /^Used memory:/ { kib = $3 } END { print running ? kib : 0 }' || true)
AVAILABLE_MIB=$(($(awk '/^MemAvailable:/ { print $2 }' /proc/meminfo) / 1024 + ${OLD_VM_KIB:-0} / 1024))
REQUIRED_MIB=$((VM_MEMORY + 2048))
if [ "$AVAILABLE_MIB" -lt "$REQUIRED_MIB" ]; then
    die "Not enough free memory: ${AVAILABLE_MIB} MiB available, ${REQUIRED_MIB} MiB required (VM_MEMORY + 2048 MiB for the host).
Stop other VMs (virsh --connect qemu:///system list) or set a lower VM_MEMORY."
fi

if "${VIRSH[@]}" dominfo "$VM_NAME" >/dev/null 2>&1; then
    log "Deleting the previous VM $VM_NAME"
fi
delete_vm "$VM_NAME"

mkdir -p "$IMAGE_DIR"
if [ ! -f "$SSH_KEY" ]; then
    # The comment must not contain "coolify": install.sh removes such lines from authorized_keys.
    ssh-keygen -q -t ed25519 -N '' -C restore-test-vm -f "$SSH_KEY"
fi

if [ -f "$BASE_IMAGE" ]; then
    log "Using the cached base image for Coolify $VERSION"
else
    build_base_image "$BASE_IMAGE"
fi

ensure_offline_network
create_vm "$VM_NAME" "$BASE_IMAGE" "$OFFLINE_NETWORK"
VM_IP=$(wait_for_ip "$VM_NAME")
log "VM IP address on the offline network: $VM_IP"
wait_for_ssh "$VM_IP"

log "Confirming that the VM has no internet access"
if vm_ssh 'timeout 5 bash -c "echo >/dev/tcp/1.1.1.1/443" 2>/dev/null || timeout 5 bash -c "echo >/dev/tcp/8.8.8.8/53" 2>/dev/null'; then
    die "The VM can reach the internet. Stopped before the backup was copied."
fi
if vm_ssh 'getent hosts cdn.coollabs.io >/dev/null'; then
    die "The VM can resolve internet host names. Stopped before the backup was copied."
fi

log "Copying the backup into the VM"
if gzip -t "$BACKUP_FILE" 2>/dev/null; then
    gzip -dc "$BACKUP_FILE" | vm_ssh 'cat >/root/coolify-backup.dmp'
else
    scp "${SSH_OPTS[@]}" "$BACKUP_FILE" "root@$VM_IP:/root/coolify-backup.dmp"
fi

log "Restoring the backup and starting Coolify (migrations run on start)"
# Send APP_KEY through stdin, so it does not show in the process list of this host or the VM.
{
    printf 'APP_KEY=%q\n' "$APP_KEY"
    cat <<'REMOTE'
set -euo pipefail
cd /data/coolify/source

for _ in $(seq 1 60); do
    [ "$(docker inspect -f '{{.State.Health.Status}}' coolify-db 2>/dev/null)" = healthy ] && break
    sleep 2
done

# Start the same image tag again after the restore.
LATEST_IMAGE=$(docker inspect -f '{{.Config.Image}}' coolify | sed 's/.*://')
docker stop coolify coolify-realtime >/dev/null

sed -i "s|^APP_KEY=.*|APP_KEY=${APP_KEY}|" .env

# Turn off Horizon (queue workers) and the scheduler: no job from the backup runs.
# Jobs that the UI dispatches stay in Redis and are never processed.
for flag in HORIZON_ENABLED SCHEDULER_ENABLED; do
    sed -i "/^${flag}=/d" .env
    echo "${flag}=false" >>.env
done

# Recreate the database: tables from a newer fresh install must not remain.
docker exec coolify-db psql -q -U coolify -d postgres -v ON_ERROR_STOP=1 \
    -c 'DROP DATABASE coolify WITH (FORCE)' -c 'CREATE DATABASE coolify OWNER coolify'
echo "Running pg_restore..."
docker exec -i coolify-db pg_restore --exit-on-error --no-acl --no-owner -U coolify -d coolify </root/coolify-backup.dmp

# Drop cached data of the fresh installation.
REDIS_PASSWORD=$(grep '^REDIS_PASSWORD=' .env | cut -d= -f2-)
docker exec coolify-redis redis-cli -a "$REDIS_PASSWORD" --no-auth-warning FLUSHALL >/dev/null

COMPOSE_FILES="-f docker-compose.yml -f docker-compose.prod.yml"
[ -f docker-compose.custom.yml ] && COMPOSE_FILES="$COMPOSE_FILES -f docker-compose.custom.yml"
[ -f docker-compose.postgres-upgrade.yml ] && COMPOSE_FILES="$COMPOSE_FILES -f docker-compose.postgres-upgrade.yml"
echo "Starting Coolify $LATEST_IMAGE..."
# --pull never: the VM is offline, all images come from the base image.
# shellcheck disable=SC2086
LATEST_IMAGE=$LATEST_IMAGE docker compose --env-file .env $COMPOSE_FILES up -d --pull never --wait --wait-timeout 600

# The container reports healthy while migrations still run on a large database.
# Seeders continue in the background; with many servers they can run for a long time.
echo "Waiting for migrations to finish..."
while docker exec coolify sh -c "ps aux | grep -q '[a]rtisan start:migration'"; do
    sleep 5
done

echo
echo "--- Migration output (docker logs coolify) ---"
docker logs coolify 2>&1 | sed -n '/Migration is enabled/,/Seeder is enabled/p'
echo
echo "--- Errors after start ---"
docker logs coolify 2>&1 | grep -iE 'error|exception|fail' | tail -n 30 || true
echo
echo "--- Pending migrations ---"
docker exec coolify php artisan migrate:status --pending || true
echo
echo "--- Queue workers and scheduler ---"
if docker exec coolify sh -c "ps aux | grep -qE '[a]rtisan (horizon|schedule:work|queue:work)'"; then
    echo "ERROR: A queue worker or the scheduler runs."
    exit 1
fi
echo "Horizon and the scheduler are off."
REMOTE
} | vm_ssh 'bash -s'

HOST_ADDR=$(hostname -f 2>/dev/null || hostname)
cat <<EOF

$(printf '\033[1;32m')Restore finished.$(printf '\033[0m')

  Coolify UI (from this host):   http://$VM_IP:8000
  From your workstation:         ssh -N -L 8000:$VM_IP:8000 -L 6001:$VM_IP:6001 -L 6002:$VM_IP:6002 root@$HOST_ADDR
                                 then open http://localhost:8000
  Log in with a user account from the backup.

  SSH into the VM:               ssh -i $SSH_KEY root@$VM_IP
  Coolify logs:                  ssh -i $SSH_KEY root@$VM_IP docker logs -f coolify
  Delete the VM:                 $0 destroy

  The VM never had internet access. Horizon and the scheduler are off, so no job runs.
EOF
