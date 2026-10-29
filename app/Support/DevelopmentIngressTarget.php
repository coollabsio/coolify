<?php

namespace App\Support;

use App\Models\Node;

/**
 * Development only: the address that `scripts/dev` forwards DEV_SEED_IP:80 to, so the generated
 * sslip.io domains of cluster applications reach an ingress Node without a manual port forward.
 */
class DevelopmentIngressTarget
{
    public static function path(): string
    {
        return storage_path('app/dev-ingress-target');
    }

    /** Writes the IP of the first ingress Node, or an empty file when no Node serves ingress. */
    public static function write(): void
    {
        if (! isDev()) {
            return;
        }
        $ip = Node::query()
            ->where('is_ingress', true)
            ->whereNotNull('node_cluster_id')
            ->orderBy('id')
            ->pluck('ip')
            ->first(fn (?string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false);
        file_put_contents(self::path(), (string) $ip);
    }
}
