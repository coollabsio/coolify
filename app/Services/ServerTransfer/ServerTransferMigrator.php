<?php

namespace App\Services\ServerTransfer;

use App\Models\Server;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class ServerTransferMigrator
{
    public function __construct(
        private ServerTransferExporter $exporter,
        private ServerTransferClaimer $claimer,
    ) {}

    /**
     * One-shot migrate: export on this instance → import+claim on target → complete on this instance.
     *
     * @return array{
     *     server_uuid: string,
     *     export_id: string|null,
     *     target_url: string,
     *     import: array<string, mixed>,
     *     complete: array<string, mixed>,
     *     warnings: list<string>,
     *     message: string
     * }
     */
    public function migrate(
        Server $server,
        string $targetUrl,
        string $targetToken,
        bool $writeRemote = false,
        bool $rebindSentinel = true,
        bool $preserveUuids = true,
        bool $adoptMode = true,
    ): array {
        if ($server->id === 0) {
            throw new RuntimeException('The Coolify host (localhost) cannot be transferred between instances.');
        }

        $targetUrl = $this->normalizeTargetUrl($targetUrl);
        $token = $this->normalizeToken($targetToken);

        $bundle = $this->exporter->export($server, includeSensitive: true);
        $exportId = data_get($bundle, 'export_id');
        $warnings = array_values((array) data_get($bundle, 'warnings', []));

        // Target import+claim runs in its own DB transaction on the remote instance.
        // Source DB is unchanged until markTransferred below — so a failed remote import leaves source intact.
        $importBody = $this->postImportToTarget(
            targetUrl: $targetUrl,
            token: $token,
            bundle: $bundle,
            writeRemote: $writeRemote,
            rebindSentinel: $rebindSentinel,
            preserveUuids: $preserveUuids,
            adoptMode: $adoptMode,
        );

        if (is_array(data_get($importBody, 'warnings'))) {
            $warnings = array_values(array_unique(array_merge($warnings, $importBody['warnings'])));
        }

        // Cross-instance 2PC is impossible: if complete fails after a successful import, the target
        // already owns the server. Surface that clearly so the operator can retry complete only.
        try {
            $complete = $this->claimer->markTransferred(
                $server,
                exportId: is_string($exportId) ? $exportId : null,
                targetInstanceUrl: $targetUrl,
            );
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Server was imported on {$targetUrl}, but this instance could not mark it as transferred: {$e->getMessage()}. ".
                'Retry complete (API: POST /api/v1/servers/{uuid}/complete) so automations stay disabled here. Do not re-import on the target.',
                previous: $e
            );
        }

        return [
            'server_uuid' => $server->uuid,
            'export_id' => is_string($exportId) ? $exportId : null,
            'target_url' => $targetUrl,
            'import' => $importBody,
            'complete' => $complete,
            'warnings' => $warnings,
            'message' => 'Server migrated to '.$targetUrl.'. Automations disabled on this instance.',
        ];
    }

    private function normalizeTargetUrl(string $targetUrl): string
    {
        $targetUrl = rtrim(trim($targetUrl), '/');
        if ($targetUrl === '' || ! filter_var($targetUrl, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('A valid target instance URL is required (e.g. http://localhost:8001).');
        }

        // From inside Docker, localhost is this container — use the host gateway for peer instances.
        if (file_exists('/.dockerenv') || is_file('/run/.containerenv')) {
            $targetUrl = (string) preg_replace(
                '#^(https?://)(localhost|127\.0\.0\.1)(?=[:/]|$)#i',
                '$1host.docker.internal',
                $targetUrl
            );
        }

        return $targetUrl;
    }

    private function normalizeToken(string $targetToken): string
    {
        $token = trim($targetToken);
        if (str_starts_with(strtolower($token), 'bearer ')) {
            $token = trim(substr($token, 7));
        }
        if ($token === '') {
            throw new RuntimeException('A target instance API token is required (root or write + create servers).');
        }

        return $token;
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return array<string, mixed>
     */
    private function postImportToTarget(
        string $targetUrl,
        string $token,
        array $bundle,
        bool $writeRemote,
        bool $rebindSentinel,
        bool $preserveUuids,
        bool $adoptMode,
    ): array {
        $importUrl = $targetUrl.'/api/v1/servers/import';

        try {
            $response = Http::timeout(120)
                ->acceptJson()
                ->withToken($token)
                ->asJson()
                ->post($importUrl, [
                    'bundle' => $bundle,
                    'dry_run' => false,
                    'preserve_uuids' => $preserveUuids,
                    'adopt_mode' => $adoptMode,
                    'claim' => true,
                    'write_remote' => $writeRemote,
                    'rebind_sentinel' => $rebindSentinel,
                ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException("Could not reach target instance at {$importUrl}: {$e->getMessage()}", previous: $e);
        } catch (Throwable $e) {
            throw new RuntimeException("Transfer to target failed: {$e->getMessage()}", previous: $e);
        }

        $importBody = $response->json();
        if (! is_array($importBody)) {
            $importBody = ['raw' => $response->body()];
        }

        if (! $response->successful()) {
            $message = data_get($importBody, 'message')
                ?? data_get($importBody, 'errors')
                ?? $response->body();
            if (is_array($message)) {
                $message = json_encode($message);
            }
            throw new RuntimeException("Target import failed (HTTP {$response->status()}): {$message}");
        }

        return $importBody;
    }
}
