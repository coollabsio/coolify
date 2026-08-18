<?php

namespace App\Livewire\Server;

use App\Models\Server;
use App\Services\ServerTransfer\ServerTransferBundle;
use App\Services\ServerTransfer\ServerTransferClaimer;
use App\Services\ServerTransfer\ServerTransferExporter;
use App\Services\ServerTransfer\ServerTransferMigrator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Throwable;

class Transfer extends Component
{
    use AuthorizesRequests;

    public Server $server;

    /** Primary one-click migrate fields */
    public string $targetUrl = '';

    public string $targetToken = '';

    public bool $writeRemote = false;

    /** Advanced */
    public bool $showAdvanced = false;

    public string $passphrase = '';

    public bool $encryptBundle = false;

    public bool $writeRemoteOnClaim = false;

    public bool $rebindSentinelOnClaim = true;

    public ?string $exportId = null;

    /** @var list<string> */
    public array $lastWarnings = [];

    public ?string $lastResultJson = null;

    public function mount(string $server_uuid): void
    {
        $this->ensureDevelopmentAvailability();

        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            $this->authorize('view', $this->server);
            $this->exportId = data_get($this->server->server_metadata, 'transfer.export_id');
        } catch (Throwable $e) {
            handleError($e, $this);
            $this->redirect(route('server.index'), navigate: true);
        }
    }

    public function getTransferStatusProperty(): ?string
    {
        return data_get($this->server->fresh()->server_metadata, 'transfer.status');
    }

    public function getIsLocalhostProperty(): bool
    {
        return (int) $this->server->id === 0;
    }

    public function migrateServer(ServerTransferMigrator $migrator): void
    {
        $this->ensureDevelopmentAvailability();

        try {
            $this->authorize('update', $this->server);
            if ($this->isLocalhost) {
                throw new \RuntimeException('The Coolify host (localhost) cannot be transferred.');
            }

            $result = $migrator->migrate(
                server: $this->server,
                targetUrl: $this->targetUrl,
                targetToken: $this->targetToken,
                writeRemote: $this->writeRemote,
            );

            $this->server->refresh();
            $this->exportId = $result['export_id'] ?? $this->exportId;
            $this->lastWarnings = array_values((array) data_get($result, 'warnings', []));
            // Never echo the target token in the result dump.
            $safe = $result;
            unset($safe['target_token']);
            $this->lastResultJson = json_encode($safe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $this->targetToken = '';
            $this->dispatch('success', $result['message'] ?? 'Server transferred.');
        } catch (Throwable $e) {
            handleError($e, $this);
        }
    }

    public function exportBundle(ServerTransferExporter $exporter)
    {
        $this->ensureDevelopmentAvailability();

        try {
            $this->authorize('view', $this->server);
            if ($this->isLocalhost) {
                throw new \RuntimeException('The Coolify host (localhost) cannot be transferred.');
            }

            $bundle = $exporter->export($this->server, includeSensitive: true);
            $this->exportId = data_get($bundle, 'export_id');
            $this->lastWarnings = array_values((array) data_get($bundle, 'warnings', []));
            $this->lastResultJson = null;

            $payload = $bundle;
            $fileName = 'server-transfer-'.$this->server->uuid.'.json';
            if ($this->encryptBundle) {
                if (blank($this->passphrase)) {
                    throw new \RuntimeException('Passphrase is required to encrypt the bundle.');
                }
                $payload = ServerTransferBundle::encryptWithPassphrase($bundle, $this->passphrase);
                $fileName = 'server-transfer-'.$this->server->uuid.'.encrypted.json';
            }

            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new \RuntimeException('Failed to encode transfer bundle.');
            }

            $this->dispatch('success', 'Transfer bundle ready for download.');

            return response()->streamDownload(function () use ($json) {
                echo $json;
            }, $fileName, [
                'Content-Type' => 'application/json',
            ]);
        } catch (Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function completeTransfer(ServerTransferClaimer $claimer): void
    {
        $this->ensureDevelopmentAvailability();

        try {
            $this->authorize('update', $this->server);
            if ($this->isLocalhost) {
                throw new \RuntimeException('The Coolify host cannot be marked as transferred.');
            }

            $result = $claimer->markTransferred(
                $this->server,
                exportId: $this->exportId ?: data_get($this->server->server_metadata, 'transfer.export_id'),
                targetInstanceUrl: filled($this->targetUrl) ? rtrim($this->targetUrl, '/') : null,
            );
            $this->server->refresh();
            $this->lastResultJson = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $this->dispatch('success', $result['message'] ?? 'Server marked as transferred.');
        } catch (Throwable $e) {
            handleError($e, $this);
        }
    }

    public function claimServer(ServerTransferClaimer $claimer): void
    {
        $this->ensureDevelopmentAvailability();

        try {
            $this->authorize('update', $this->server);
            if ($this->isLocalhost) {
                throw new \RuntimeException('The Coolify host cannot be claimed.');
            }

            $result = $claimer->claim(
                $this->server,
                writeRemote: $this->writeRemoteOnClaim,
                rebindSentinel: $this->rebindSentinelOnClaim,
            );
            $this->server->refresh();
            $this->lastResultJson = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $this->lastWarnings = [];
            $this->dispatch('success', $result['message'] ?? 'Server claimed.');
        } catch (Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.transfer');
    }

    private function ensureDevelopmentAvailability(): void
    {
        abort_unless(isDev(), 404);
    }
}
