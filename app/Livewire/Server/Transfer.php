<?php

namespace App\Livewire\Server;

use App\Models\Server;
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

    public ?string $exportId = null;

    /** Transfer back fields, shown for an archive */
    public string $managerUrl = '';

    public string $managerToken = '';

    public string $thisInstanceUrl = '';

    /** @var list<string> */
    public array $lastWarnings = [];

    public function mount(string $server_uuid): void
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            // The transfer exports every credential of the server, so only admins and owners may open it.
            $this->authorize('update', $this->server);
            $this->exportId = data_get($this->server->server_metadata, 'transfer.export_id');
            // Transfer back sends a new team token to this URL, so it is prefilled only when this instance chose it.
            $managerUrl = data_get($this->server->server_metadata, 'transfer.target_instance_url');
            if (is_string($managerUrl) && ! data_get($this->server->server_metadata, 'transfer.claimed_by_marker')
                && ServerTransferMigrator::isValidInstanceUrl($managerUrl)) {
                $this->managerUrl = $managerUrl;
            }
            $this->thisInstanceUrl = rtrim((string) (instanceSettings()->fqdn ?: config('app.url')), '/');
        } catch (Throwable $e) {
            handleError($e, $this);
            $this->redirect(route('server.index'), navigate: true);
        }
    }

    public function getIsLocalhostProperty(): bool
    {
        return (int) $this->server->id === 0;
    }

    /**
     * State of the queued transfer to another instance, read by the page poll.
     *
     * @return array<string, mixed>|null
     */
    public function getMigrationProperty(): ?array
    {
        $migration = data_get($this->server->server_metadata, 'migration');

        return is_array($migration) ? $migration : null;
    }

    /**
     * Manual steps of a finished transfer that the target instance cannot do.
     *
     * @return list<string>
     */
    public function getPostTransferChecklistProperty(): array
    {
        if (data_get($this->migration, 'status') !== 'succeeded') {
            return [];
        }

        return array_values(array_filter((array) data_get($this->migration, 'checklist', []), 'is_string'));
    }

    /**
     * The link to the server on the target. The target sends it, so only an https link is shown.
     */
    public function getTargetServerUrlProperty(): ?string
    {
        $url = data_get($this->migration, 'target_server_url');

        return ServerTransferMigrator::isLinkableUrl($url) ? $url : null;
    }

    public function refreshMigration(): void
    {
        $this->authorize('update', $this->server);
        $this->server->refresh();
        $this->lastWarnings = array_values((array) data_get($this->migration, 'warnings', $this->lastWarnings));
    }

    public function migrateServer(ServerTransferMigrator $migrator): void
    {
        try {
            $this->authorize('transfer', $this->server);
            if ($this->isLocalhost) {
                throw new \RuntimeException('The Coolify host (localhost) cannot be transferred.');
            }

            $migrator->start(
                server: $this->server,
                targetUrl: $this->targetUrl,
                targetToken: $this->targetToken,
            );

            auditLog('ui.server.migrate', [
                'team_id' => $this->server->team_id,
                'server_uuid' => $this->server->uuid,
                'target_url' => $this->targetUrl,
            ]);

            $this->server->refresh();
            $this->lastWarnings = [];
            $this->dispatch('success', 'All checks passed. The transfer started.');
        } catch (Throwable $e) {
            // A failed check is saved in the transfer log, so show it.
            $this->server->refresh();
            handleError($e, $this);
        } finally {
            // Public properties are sent to the browser with every response.
            $this->targetToken = '';
        }
    }

    public function transferBack(ServerTransferMigrator $migrator): void
    {
        try {
            $this->authorize('update', $this->server);
            $migrator->requestTransferBack($this->server, auth()->user(), $this->managerUrl, $this->managerToken, $this->thisInstanceUrl);

            auditLog('ui.server.transfer_back', [
                'team_id' => $this->server->team_id,
                'server_uuid' => $this->server->uuid,
                'from' => $this->managerUrl,
            ]);

            $this->server->refresh();
            $this->dispatch('success', 'The other instance started the transfer back.');
        } catch (Throwable $e) {
            handleError($e, $this);
        } finally {
            $this->managerToken = '';
        }
    }

    public function render()
    {
        return view('livewire.server.transfer');
    }
}
