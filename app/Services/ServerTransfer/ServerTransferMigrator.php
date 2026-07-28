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

        $targetUrl = rtrim(trim($targetUrl), '/');
        if ($targetUrl === '' || ! filter_var($targetUrl, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('A valid target instance URL is required (e.g. http://localhost:8001).');
        }

        $token = trim($targetToken);
        $token = str_starts_with(strtolower($token), 'bearer ')
            ? trim(substr($token, 7))
            : $token;
        if ($token === '') {
            throw new RuntimeException('A target instance API token is required (root or write + ability to create servers).');
        }

        $bundle = $this->exporter->export($server, includeSensitive: true);
        $exportId = data_get($bundle, 'export_id');
        $warnings = array_values((array) data_get($bundle, 'warnings', []));

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
            throw new RuntimeException(
                "Could not reach target instance at {$importUrl}: {$e->getMessage()}",
                previous: $e
            );
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Transfer to target failed: {$e->getMessage()}",
                previous: $e
            );
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
            throw new RuntimeException(
                "Target import failed (HTTP {$response->status()}): {$message}"
            );
        }

        if (is_array(data_get($importBody, 'warnings'))) {
            $warnings = array_values(array_unique(array_merge($warnings, $importBody['warnings'])));
        }

        $complete = $this->claimer->markTransferred(
            $server,
            exportId: is_string($exportId) ? $exportId : null,
            targetInstanceUrl: $targetUrl,
        );

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
}
