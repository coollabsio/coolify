<?php

namespace App\Livewire\Server\Proxy;

use App\Actions\Proxy\DeleteTraefikAcmeBackup;
use App\Actions\Proxy\DeleteTraefikCertificate;
use App\Actions\Proxy\GetTraefikCertificates;
use App\Actions\Proxy\ListTraefikAcmeBackups;
use App\Actions\Proxy\RestoreTraefikAcmeBackup;
use App\Jobs\RestartProxyJob;
use App\Models\Server;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class Certificates extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public array $traefikCertificates = [];

    public bool $traefikCertificatesLoaded = false;

    /** @var array<int, array{name: string, created_at: string, size: int}> */
    public array $traefikAcmeBackups = [];

    public function loadTraefikCertificates(): void
    {
        $this->traefikCertificates = [];

        try {
            $this->authorize('view', $this->server);
            $this->traefikCertificates = GetTraefikCertificates::run($this->server);
            $this->traefikCertificatesLoaded = true;
        } catch (\Throwable $e) {
            $this->traefikCertificatesLoaded = true;
            handleError($e, $this);
        }

        $this->loadTraefikAcmeBackups();
    }

    private function loadTraefikAcmeBackups(): void
    {
        $this->traefikAcmeBackups = [];

        try {
            if (Gate::allows('manageProxy', $this->server)) {
                $this->traefikAcmeBackups = ListTraefikAcmeBackups::run($this->server);
            }
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    /** Default of the "Restart the proxy now" option in the acme.json restore dialog. */
    public bool $restartProxyAfterAcmeRestore = true;

    /**
     * @param  array<int, string>  $selectedActions  checkbox ids selected in the restore dialog
     */
    public function restoreTraefikAcmeBackup(string $backupName, string $password = '', array $selectedActions = []): void
    {
        try {
            $this->authorize('manageProxy', $this->server);
            RestoreTraefikAcmeBackup::run($this->server, $backupName);
            auditLog('ui.proxy.acme_backup_restored', [
                'team_id' => $this->server->team_id,
                'server_uuid' => $this->server->uuid,
                'server_name' => $this->server->name,
                'backup' => $backupName,
            ]);
            $this->loadTraefikCertificates();

            // A running Traefik keeps its certificates in memory and can write them back to acme.json.
            if (in_array('restartProxyAfterAcmeRestore', $selectedActions, true)) {
                RestartProxyJob::dispatch($this->server);
                auditLog('ui.proxy.restarted', [
                    'team_id' => $this->server->team_id,
                    'server_uuid' => $this->server->uuid,
                    'server_name' => $this->server->name,
                ]);
                $this->dispatch('refreshServerShow');
                $this->dispatch('success', 'acme.json restored. The proxy is restarting to load the restored certificates.');

                return;
            }

            $this->dispatch('refreshServerShow');
            $this->dispatch('success', 'acme.json restored. Restart the proxy to load the restored certificates.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function deleteTraefikAcmeBackup(string $backupName, string $password = ''): void
    {
        try {
            $this->authorize('manageProxy', $this->server);
            DeleteTraefikAcmeBackup::run($this->server, $backupName);
            auditLog('ui.proxy.acme_backup_deleted', [
                'team_id' => $this->server->team_id,
                'server_uuid' => $this->server->uuid,
                'server_name' => $this->server->name,
                'backup' => $backupName,
            ]);
            $this->loadTraefikAcmeBackups();
            $this->dispatch('success', 'acme.json backup deleted.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function deleteTraefikCertificate(string $certificateId, string $password = ''): void
    {
        try {
            $this->authorize('update', $this->server);
            $certificate = DeleteTraefikCertificate::run($this->server, $certificateId);
            auditLog('ui.proxy.certificate_deleted', [
                'team_id' => $this->server->team_id,
                'server_uuid' => $this->server->uuid,
                'server_name' => $this->server->name,
                'domain' => $certificate['main_domain'],
                'resolver' => $certificate['resolver'],
            ]);
            $this->traefikCertificates = GetTraefikCertificates::run($this->server);
            $this->loadTraefikAcmeBackups();
            $this->dispatch('refreshServerShow');
            $this->dispatch('success', 'TLS certificate deleted. Restart Traefik to remove it from the running proxy.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render(): View
    {
        return view('livewire.server.proxy.certificates');
    }
}
