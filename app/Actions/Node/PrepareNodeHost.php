<?php

namespace App\Actions\Node;

use App\Models\Node;
use Lorisleiva\Actions\Concerns\AsAction;

class PrepareNodeHost
{
    use AsAction;

    public function handle(Node $node): string
    {
        return instant_remote_process([self::installationScript()], $node, timeout: 900, disableMultiplexing: true);
    }

    public static function installationScript(): string
    {
        return <<<'SH'
set -eu
if ! command -v podman >/dev/null || ! command -v wg >/dev/null || ! command -v nft >/dev/null || ! command -v iptables >/dev/null || ! command -v curl >/dev/null; then
    if command -v apt-get >/dev/null; then
        export DEBIAN_FRONTEND=noninteractive
        apt-get update
        apt-get install -y podman wireguard-tools nftables iptables curl ca-certificates
    elif command -v dnf >/dev/null; then
        dnf install -y podman wireguard-tools nftables iptables curl ca-certificates
    elif command -v yum >/dev/null; then
        yum install -y podman wireguard-tools nftables iptables curl ca-certificates
    else
        echo 'No supported package manager was found.' >&2
        exit 1
    fi
fi
systemctl enable --now podman.socket
systemctl is-active --quiet podman.socket
ln -sfn /run/podman/podman.sock /var/run/docker.sock
test -S /var/run/docker.sock
podman info --format json >/dev/null
SH;
    }
}
