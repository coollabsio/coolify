<?php

namespace App\Livewire\Server;

use App\Actions\Server\AuthorizedKeys;
use App\Actions\Server\DeleteServer;
use App\Jobs\DeleteResourceJob;
use App\Models\Server;
use App\Services\ServerTransfer\ServerTransferClaimer;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Delete extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public bool $delete_from_hetzner = false;

    public bool $delete_from_vultr = false;

    public bool $delete_from_digitalocean = false;

    public bool $delete_from_hostinger = false;

    public bool $force_delete_resources = false;

    public bool $remove_ssh_key = false;

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function delete($password, $selectedActions = [])
    {
        if (! verifyPasswordConfirmation($password, $this)) {
            return 'The provided password is incorrect.';
        }

        if (! empty($selectedActions)) {
            $this->delete_from_hetzner = in_array('delete_from_hetzner', $selectedActions);
            $this->delete_from_vultr = in_array('delete_from_vultr', $selectedActions);
            $this->delete_from_digitalocean = in_array('delete_from_digitalocean', $selectedActions);
            $this->delete_from_hostinger = in_array('delete_from_hostinger', $selectedActions);
            $this->force_delete_resources = in_array('force_delete_resources', $selectedActions);
            $this->remove_ssh_key = in_array('remove_ssh_key', $selectedActions);
        }
        // Another Coolify instance manages a transferred server. This instance deletes only its own records:
        // never the VM, and never the containers that the other instance runs.
        $isArchive = $this->server->isTransferredAway();
        if ($isArchive) {
            $this->delete_from_hetzner = false;
            $this->delete_from_vultr = false;
            $this->delete_from_digitalocean = false;
            $this->delete_from_hostinger = false;
            $this->force_delete_resources = true;
            $this->remove_ssh_key = false;
        }
        try {
            $this->authorize('delete', $this->server);
            if ($this->server->is_coolify_host) {
                $this->dispatch('error', 'The Coolify host server cannot be deleted.');

                return;
            }
            $resources = $this->server->primaryResources();
            if ($resources->isNotEmpty() && ! $this->force_delete_resources) {
                $this->dispatch('error', 'Server has defined resources. Please delete them first or select "Delete all resources".');

                return;
            }

            if ($this->remove_ssh_key && ! $this->deletesCloudServer() && ! $this->removeSshKey()) {
                return;
            }

            if ($isArchive) {
                app(ServerTransferClaimer::class)->forgetDnsRecords($this->server);
            }
            // Applications that use the server only as an additional destination stay; they lose this server.
            // Another instance manages a transferred server, so the containers stay as they are there.
            $this->server->detachAdditionalApplications(stopContainers: ! $isArchive);
            if ($this->force_delete_resources) {
                foreach ($resources as $resource) {
                    DeleteResourceJob::dispatch($resource, deleteFromCoolifyOnly: $isArchive);
                }
            }

            $this->server->delete();
            DeleteServer::dispatch(
                $this->server->id,
                $this->delete_from_hetzner,
                $this->server->hetzner_server_id,
                $this->server->cloud_provider_token_id,
                $this->server->team_id,
                $this->delete_from_vultr,
                $this->server->vultr_instance_id,
                $this->delete_from_digitalocean,
                $this->server->digitalocean_droplet_id,
                $this->delete_from_hostinger,
                $this->server->hostinger_virtual_machine_id
            );

            return redirectRoute($this, 'server.index');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function deletesCloudServer(): bool
    {
        return $this->delete_from_hetzner || $this->delete_from_vultr || $this->delete_from_digitalocean || $this->delete_from_hostinger;
    }

    /**
     * The key is removed before the server record, while the connection still works.
     * Resources must be deleted first, because their delete jobs need the connection.
     */
    private function removeSshKey(): bool
    {
        if ($this->server->definedResources()->isNotEmpty()) {
            $this->dispatch('error', 'Delete the resources of the server first, then delete the server and remove the SSH key.');

            return false;
        }

        $context = [
            'team_id' => $this->server->team_id,
            'server_uuid' => $this->server->uuid,
            'server_name' => $this->server->name,
            'private_key_uuid' => $this->server->privateKey?->uuid,
        ];
        try {
            $removed = AuthorizedKeys::remove($this->server, $this->server->privateKey);
        } catch (\Throwable $e) {
            auditLog('ui.server.ssh_key_removed', [...$context, 'result' => 'failed'], 'warning');
            $this->dispatch('error', 'The SSH key was not removed from the server, so the server was not deleted. Clear the option to delete the server without removing the key.<br><br>Error: '.htmlspecialchars(remove_iip($e->getMessage()), ENT_QUOTES, 'UTF-8'));

            return false;
        }
        auditLog('ui.server.ssh_key_removed', [...$context, 'result' => $removed ? 'removed' : 'kept_for_other_server']);

        return true;
    }

    public function render()
    {
        $checkboxes = [];

        $resourcesToDelete = $this->server->primaryResources();
        $detachedApplications = $this->server->additionalApplications();

        if ($resourcesToDelete->isNotEmpty() && ! $this->server->isTransferredAway()) {
            $checkboxes[] = [
                'id' => 'force_delete_resources',
                'label' => "Delete all resources ({$resourcesToDelete->count()} total): ".$resourcesToDelete->pluck('name')->implode(', '),
                'default_warning' => 'Server cannot be deleted while it has resources.',
            ];
        }

        $canDeleteFromProvider = ! $this->server->isTransferredAway();

        if ($canDeleteFromProvider && $this->server->definedResources()->isEmpty() && AuthorizedKeys::canRunOn($this->server)) {
            $checkboxes[] = [
                'id' => 'remove_ssh_key',
                'label' => 'Remove the public key of this server\'s SSH key from ~/.ssh/authorized_keys on the server',
                'default_warning' => 'The public key stays on the server, so this SSH key can still log in.',
            ];
        }

        if ($canDeleteFromProvider && $this->server->hetzner_server_id) {
            $checkboxes[] = [
                'id' => 'delete_from_hetzner',
                'label' => 'Also delete server from Hetzner Cloud',
                'default_warning' => 'The actual server on Hetzner Cloud will NOT be deleted.',
            ];
        }

        if ($canDeleteFromProvider && $this->server->vultr_instance_id) {
            $checkboxes[] = [
                'id' => 'delete_from_vultr',
                'label' => 'Also delete server from Vultr',
                'default_warning' => 'The actual server on Vultr will NOT be deleted.',
            ];
        }

        if ($canDeleteFromProvider && $this->server->digitalocean_droplet_id) {
            $checkboxes[] = [
                'id' => 'delete_from_digitalocean',
                'label' => 'Also delete droplet from DigitalOcean',
                'default_warning' => 'The actual droplet on DigitalOcean will NOT be deleted.',
            ];
        }

        if ($canDeleteFromProvider && $this->server->hostinger_virtual_machine_id) {
            $checkboxes[] = [
                'id' => 'delete_from_hostinger',
                'label' => 'Also delete VPS from Hostinger (at the end of the paid period)',
                'default_warning' => 'The VPS on Hostinger will NOT be deleted, and Hostinger will keep billing for it.',
            ];
        }

        return view('livewire.server.delete', [
            'checkboxes' => $checkboxes,
            'resourcesToDelete' => $resourcesToDelete,
            'detachedApplications' => $detachedApplications,
        ]);
    }
}
