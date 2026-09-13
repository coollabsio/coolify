<?php

namespace App\Actions\Node;

use App\Models\Node;
use Lorisleiva\Actions\Concerns\AsAction;

class FetchNodeDiscoveryEndpoints
{
    use AsAction;

    /** @return array<int, array{hostname: string, workload: string, namespace: string, owner_node_ip: string, container_ip: string, state: string, health: string, updated_at: int, expires_at: int}> */
    public function handle(Node $node): array
    {
        $sql = 'SELECT workload_id, namespace, owner_node_ip, container_ip, state, health, updated_at, expires_at FROM workload_endpoints ORDER BY namespace, workload_id';
        $output = instant_remote_process(
            ['/usr/local/bin/corrosion query --config /etc/corrosion/config.toml '.escapeshellarg($sql)],
            $node,
            timeout: 30,
            disableMultiplexing: true,
        );

        return self::parse($output);
    }

    /** @return array<int, array{hostname: string, workload: string, namespace: string, owner_node_ip: string, container_ip: string, state: string, health: string, updated_at: int, expires_at: int}> */
    public static function parse(string $output): array
    {
        return collect(preg_split('/\R/', trim($output)) ?: [])
            ->map(function (string $line): ?array {
                $fields = explode('|', trim($line));
                if (count($fields) !== 8) {
                    return null;
                }
                [$workload, $namespace, $ownerNodeIp, $containerIp, $state, $health, $updatedAt, $expiresAt] = $fields;
                if (! self::validLabel($workload)
                    || ! self::validLabel($namespace)
                    || filter_var($ownerNodeIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
                    || filter_var($containerIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
                    || ! ctype_digit($updatedAt)
                    || ! ctype_digit($expiresAt)) {
                    return null;
                }

                return [
                    'hostname' => "{$workload}.{$namespace}.coolify.internal",
                    'workload' => $workload,
                    'namespace' => $namespace,
                    'owner_node_ip' => $ownerNodeIp,
                    'container_ip' => $containerIp,
                    'state' => $state,
                    'health' => $health,
                    'updated_at' => (int) $updatedAt,
                    'expires_at' => (int) $expiresAt,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private static function validLabel(string $value): bool
    {
        return preg_match('/\A(?!-)[a-zA-Z0-9-]{1,63}(?<!-)\z/', $value) === 1;
    }
}
