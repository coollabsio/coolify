<?php

namespace App\Actions\Node;

use App\Models\NodeCluster;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

class RepairNodeClusterNetwork
{
    use AsAction;

    /** @return array<string, ?string> */
    public function handle(NodeCluster $cluster): array
    {
        $outputs = [];
        $cluster->nodes()
            ->where('team_id', $cluster->team_id)
            ->orderBy('id')
            ->get()
            ->each(function ($node) use ($cluster, &$outputs): void {
                $outputs[$node->uuid] = instant_remote_process(
                    [InstallSentinel::remoteCommand(self::repairScript($cluster->wireguard_interface))],
                    $node,
                    timeout: 300,
                    disableMultiplexing: true,
                );
            });

        return $outputs;
    }

    public static function repairScript(string $interface): string
    {
        if (! preg_match('/\A[a-zA-Z0-9_.-]{1,15}\z/', $interface)) {
            throw new InvalidArgumentException('The WireGuard interface is invalid.');
        }

        return <<<SCRIPT
set -euo pipefail
umask 077

interface='{$interface}'
wireguard_config='/etc/wireguard/{$interface}.conf'
wireguard_last_good='/var/lib/coolify/network/{$interface}.last-good.conf'
firewall_last_good='/var/lib/coolify/network/firewall.last-good.nft'
firewall_transaction="\$(mktemp /tmp/coolify-firewall-repair.XXXXXX)"
trap 'rm -f "\$firewall_transaction"' EXIT

systemctl stop "coolify-network-rollback-\$interface.timer" 2>/dev/null || true
systemctl stop coolify-firewall-rollback.timer 2>/dev/null || true

test -s "\$wireguard_last_good"
install -m 0600 "\$wireguard_last_good" "\$wireguard_config"
wg-quick strip "\$wireguard_config" >/dev/null
wg-quick down "\$interface" >/dev/null 2>&1 || true
wg-quick up "\$interface"
wg show "\$interface"
wireguard_address="\$(ip -4 -o address show dev "\$interface" scope global | awk '{ split(\$4, address, "/"); print address[1]; exit }')"
test -n "\$wireguard_address"
resolvectl dns "\$interface" "\$wireguard_address"
reverse_domains="\$(
    {
        printf '%s\n' "\$wireguard_address"
        wg show "\$interface" allowed-ips | awk '{ for (field = 2; field <= NF; field++) print \$field }'
    } | awk -F'[./]' 'NF >= 4 { print "~" \$4 "." \$3 "." \$2 "." \$1 ".in-addr.arpa" }' | sort -u | tr '\n' ' '
)"
test -n "\$reverse_domains"
resolvectl domain "\$interface" ~coolify.internal \$reverse_domains

test -s "\$firewall_last_good"
if nft list table inet coolify_cluster >/dev/null 2>&1; then
    printf '%s\n' 'delete table inet coolify_cluster' > "\$firewall_transaction"
fi
cat "\$firewall_last_good" >> "\$firewall_transaction"
nft --check --file "\$firewall_transaction"
nft --file "\$firewall_transaction"

if systemctl list-unit-files corrosion.service >/dev/null 2>&1; then
    systemctl restart corrosion.service
fi
if systemctl list-unit-files coolify-discovery-dns.service >/dev/null 2>&1; then
    systemctl restart coolify-discovery-dns.service
    systemctl is-active --quiet coolify-discovery-dns.service
fi
systemctl restart sentinel.service
systemctl is-active --quiet sentinel.service
SCRIPT;
    }
}
