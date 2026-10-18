<?php

namespace App\Livewire\Server;

use App\Models\Server;
use App\Services\ServerTransfer\ServerTransferBundle;
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

    /** Manual transfer */
    /** Encrypts the downloaded file when filled; empty or whitespace means no encryption. */
    public ?string $passphrase = null;

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
            if (filled($this->passphrase)) {
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

    public function render()
    {
        return view('livewire.server.transfer');
    }

    private function ensureDevelopmentAvailability(): void
    {
        abort_unless(isDev(), 404);
    }
}
