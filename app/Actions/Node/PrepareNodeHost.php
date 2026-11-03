<?php

namespace App\Actions\Node;

use App\Models\Node;
use Lorisleiva\Actions\Concerns\AsAction;

class PrepareNodeHost
{
    use AsAction;

    /**
     * Every Node runs this Podman version from github.com/coollabsio/podman, not the Podman of its distribution.
     * To change it, set the version and the SHA-256 values from the SHA256SUMS file of its release.
     */
    public const PODMAN_VERSION = '6.1.4';

    /** @var array{amd64: string, arm64: string} */
    public const PODMAN_SHA256 = [
        'amd64' => 'e5cac42d2a3acbefbacb63b7af7cd8206c75dd34b181f72bba9ccd8f17aed649',
        'arm64' => '450173eee70c97ea05dbefe455ab3056c74ed2239f9d367012dac3a53038a338',
    ];

    public function handle(Node $node): string
    {
        return instant_remote_process([InstallSentinel::hostCommand($node, self::installationScript())], $node, timeout: 900, disableMultiplexing: true);
    }

    public static function installationScript(): string
    {
        return sprintf(
            "set -eu\npodman_version=%s\npodman_amd64_sha256=%s\npodman_arm64_sha256=%s\n",
            self::PODMAN_VERSION,
            self::PODMAN_SHA256['amd64'],
            self::PODMAN_SHA256['arm64'],
        ).<<<'SH'
case "$(uname -m)" in
    x86_64) podman_arch=amd64 podman_sha256="$podman_amd64_sha256" ;;
    aarch64) podman_arch=arm64 podman_sha256="$podman_arm64_sha256" ;;
    *) echo "Podman is not available for the $(uname -m) architecture." >&2; exit 1 ;;
esac
if ! command -v wg >/dev/null || ! command -v nft >/dev/null || ! command -v iptables >/dev/null || ! command -v curl >/dev/null || ! command -v rsync >/dev/null; then
    if command -v apt-get >/dev/null; then
        export DEBIAN_FRONTEND=noninteractive
        apt-get update
        apt-get install -y wireguard-tools nftables iptables curl rsync ca-certificates
    elif command -v dnf >/dev/null; then
        dnf install -y wireguard-tools nftables iptables curl rsync ca-certificates container-selinux
    elif command -v yum >/dev/null; then
        yum install -y wireguard-tools nftables iptables curl rsync ca-certificates container-selinux
    else
        echo 'No supported package manager was found.' >&2
        exit 1
    fi
fi
if [ "$(cat /etc/coolify/podman-version 2>/dev/null || true)" != "$podman_version" ]; then
    if [ -e /var/lib/containers/storage/libpod/bolt_state.db ]; then
        echo "This server has containers of an older Podman that Podman $podman_version cannot read. Remove them, or use a clean server." >&2
        exit 1
    fi
    podman_download="$(mktemp -d)"
    curl -fsSL -o "$podman_download/podman.tar.gz" "https://github.com/coollabsio/podman/releases/download/v$podman_version/podman-linux-$podman_arch.tar.gz"
    echo "$podman_sha256  $podman_download/podman.tar.gz" | sha256sum -c -
    tar -xzf "$podman_download/podman.tar.gz" -C "$podman_download"
    systemctl stop podman.socket podman.service 2>/dev/null || true
    cp -r "$podman_download/podman-linux-$podman_arch/usr" "$podman_download/podman-linux-$podman_arch/etc" /
    rm -rf "$podman_download"
    systemctl daemon-reload
    install -d -m 0700 /etc/coolify
    printf '%s\n' "$podman_version" > /etc/coolify/podman-version
fi
systemctl enable --now podman.socket
systemctl is-active --quiet podman.socket
# /run is a tmpfs, so a plain symlink is lost at each boot.
printf 'L+ /run/docker.sock - - - - /run/podman/podman.sock\n' > /etc/tmpfiles.d/coolify-docker-socket.conf
systemd-tmpfiles --create /etc/tmpfiles.d/coolify-docker-socket.conf
test -S /var/run/docker.sock
podman info --format json >/dev/null
SH;
    }
}
