<?php

namespace App\Actions\Sentinel;

use App\Models\Node;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Deliver the current Flux CA trust bundle to one Node over its authenticated
 * Flux connection (`trust.bundle.update.v1`) and record the acknowledged version.
 *
 * Trust anchors are never delivered through the assignment polling channel.
 * Offline Nodes are retried when they reconnect and by the scheduled job; Nodes
 * whose Sentinel lacks the capability must be repaired over SSH.
 */
class DistributeFluxTrustBundle
{
    use AsAction;

    public const CAPABILITY = 'trust.bundle.update.v1';

    public const MAX_BUNDLE_BYTES = 64 * 1024;

    public const ACKNOWLEDGED = 'acknowledged';

    public const CURRENT = 'current';

    public const OFFLINE = 'offline';

    public const UNSUPPORTED = 'unsupported';

    public const FAILED = 'failed';

    /**
     * @return self::ACKNOWLEDGED|self::CURRENT|self::OFFLINE|self::UNSUPPORTED|self::FAILED
     */
    public function handle(Node $node): string
    {
        $bundle = ResolveFluxTrustBundle::run();
        $installed = $node->flux_trust_bundle_version ?? self::reportedVersion($node);
        if ($installed !== null && $node->flux_trust_bundle_version === null) {
            self::recordInstalledVersion($node, $installed);
        }
        if ($installed !== null && $installed >= $bundle['version']) {
            return self::CURRENT;
        }
        if (! $node->hasRecentFluxHeartbeat()) {
            return self::OFFLINE;
        }
        if ($node->supportsCapability(self::CAPABILITY) !== true) {
            $this->recordError($node, 'Sentinel cannot receive trust bundles over Flux. Upgrade Sentinel or repair trust over SSH.');

            return self::UNSUPPORTED;
        }

        $url = config('constants.flux.internal_url');
        $token = config('constants.flux.internal_token');
        if (! is_string($url) || $url === '' || ! is_string($token) || $token === '') {
            throw new RuntimeException('Flux internal API configuration is incomplete.');
        }
        if (strlen($bundle['certificate_pem']) > self::MAX_BUNDLE_BYTES) {
            throw new RuntimeException('The Flux trust bundle exceeds the size limit.');
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(30)
                ->post(rtrim($url, '/').'/v1/commands/trust.bundle.update', [
                    'server_id' => $node->uuid,
                    'command_id' => 'trust-bundle-'.$bundle['version'].'-'.Str::uuid(),
                    'version' => $bundle['version'],
                    'bundle_pem' => $bundle['certificate_pem'],
                ]);
        } catch (ConnectionException) {
            return self::OFFLINE;
        }

        if ($response->status() === 404) {
            return self::OFFLINE;
        }
        if ($response->status() === 409) {
            $this->recordError($node, 'Sentinel cannot receive trust bundles over Flux. Upgrade Sentinel or repair trust over SSH.');

            return self::UNSUPPORTED;
        }
        if ($response->failed()) {
            $this->recordError($node, 'Trust bundle update failed: '.Str::limit(trim($response->body()) ?: "HTTP {$response->status()}", 400));

            return self::FAILED;
        }

        $payload = $response->json();
        $validator = Validator::make(is_array($payload) ? $payload : [], [
            'installed_version' => ['required', 'integer', 'in:'.$bundle['version']],
        ]);
        if ($validator->fails()) {
            $this->recordError($node, 'Flux returned an invalid trust bundle acknowledgement.');

            return self::FAILED;
        }

        self::recordInstalledVersion($node, $bundle['version']);

        return self::ACKNOWLEDGED;
    }

    /**
     * Record the trust bundle version that a Node has installed.
     */
    public static function recordInstalledVersion(Node $node, int $version): void
    {
        $node->forceFill([
            'flux_trust_bundle_version' => $version,
            'flux_trust_bundle_acknowledged_at' => now(),
            'flux_trust_bundle_error' => null,
        ])->saveQuietly();
    }

    /**
     * The version Sentinel reported in its last Flux hello.
     */
    private static function reportedVersion(Node $node): ?int
    {
        $version = data_get(Cache::get($node->cacheKey()), 'trust_bundle_version');

        return is_int($version) && $version > 0 ? $version : null;
    }

    private function recordError(Node $node, string $message): void
    {
        $node->forceFill(['flux_trust_bundle_error' => Str::limit($message, 490)])->saveQuietly();
    }
}
