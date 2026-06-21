<?php

namespace App\Traits;

use App\Helpers\SslHelper;
use Carbon\Carbon;
use Exception;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

/**
 * Shared behavior for the per-database StatusInfo Livewire siblings.
 *
 * Lives on a child Livewire component so status broadcasts never trigger a
 * roundtrip on the parent form — preserving in-progress typing AND wire:dirty.
 * See coolify#6062 / #6354 / #9695.
 *
 * Consumers must declare a typed `public Model $database` and implement
 * databaseLabel(). All other hooks have sensible defaults.
 */
trait HasDatabaseStatusInfo
{
    public ?string $dbUrl = null;

    public ?string $dbUrlPublic = null;

    public bool $enableSsl = false;

    public ?string $sslMode = null;

    public ?Carbon $certificateValidUntil = null;

    abstract protected function databaseLabel(): string;

    protected function supportsSsl(): bool
    {
        return true;
    }

    protected function sslModeOptions(): ?array
    {
        return null;
    }

    protected function sslModeHelper(): ?string
    {
        return null;
    }

    protected function showPublicUrlPlaceholder(): bool
    {
        return false;
    }

    public function getListeners(): array
    {
        $listeners = ['databaseUpdated' => 'refresh'];

        $user = Auth::user();
        if (! $user) {
            return $listeners;
        }

        $listeners["echo-private:user.{$user->id},DatabaseStatusChanged"] = 'refresh';

        $team = $user->currentTeam();
        if ($team) {
            $listeners["echo-private:team.{$team->id},ServiceChecked"] = 'refresh';
        }

        return $listeners;
    }

    public function mount(): void
    {
        $this->refresh();
    }

    public function refresh(): void
    {
        $this->database->refresh();
        $this->dbUrl = $this->database->internal_db_url;
        $this->dbUrlPublic = $this->database->external_db_url;
        if ($this->supportsSsl()) {
            $this->enableSsl = (bool) $this->database->enable_ssl;
            $this->certificateValidUntil = $this->database->sslCertificates()->first()?->valid_until;
            $this->afterRefresh();
        }
    }

    /**
     * Hook for subclasses with extra status-derived properties (e.g. sslMode).
     */
    protected function afterRefresh(): void {}

    public function instantSaveSSL(): void
    {
        try {
            $this->authorize('update', $this->database);
            $this->database->enable_ssl = $this->enableSsl;
            $this->applyExtraSslAttributes();
            $this->database->save();
            $this->dispatch('success', 'SSL configuration updated.');
        } catch (Exception $e) {
            handleError($e, $this);
        }
    }

    /**
     * Hook for subclasses with additional SSL columns to persist (e.g. ssl_mode).
     */
    protected function applyExtraSslAttributes(): void {}

    public function regenerateSslCertificate(): void
    {
        try {
            $this->authorize('update', $this->database);

            $existingCert = $this->database->sslCertificates()->first();

            if (! $existingCert) {
                $this->dispatch('error', 'No existing SSL certificate found for this database.');

                return;
            }

            $server = $this->database->destination->server;
            $caCert = $server->sslCertificates()->where('is_ca_certificate', true)->first();

            if (! $caCert) {
                $server->generateCaCertificate();
                $caCert = $server->sslCertificates()->where('is_ca_certificate', true)->first();
            }

            if (! $caCert) {
                $this->dispatch('error', 'No CA certificate found for this database. Please generate a CA certificate for this server in the server/advanced page.');

                return;
            }

            SslHelper::generateSslCertificate(
                commonName: $existingCert->common_name,
                subjectAlternativeNames: $existingCert->subject_alternative_names ?? [],
                resourceType: $existingCert->resource_type,
                resourceId: $existingCert->resource_id,
                serverId: $existingCert->server_id,
                caCert: $caCert->ssl_certificate,
                caKey: $caCert->ssl_private_key,
                configurationDir: $existingCert->configuration_dir,
                mountPath: $existingCert->mount_path,
                isPemKeyFileRequired: true,
            );

            $this->refresh();
            $this->dispatch('success', 'SSL certificates regenerated. Restart database to apply changes.');
        } catch (Exception $e) {
            handleError($e, $this);
        }
    }

    public function render(): View
    {
        return view('livewire.project.database.status-info', [
            'label' => $this->databaseLabel(),
            'supportsSsl' => $this->supportsSsl(),
            'sslModeOptions' => $this->sslModeOptions(),
            'sslModeHelper' => $this->sslModeHelper(),
            'showPublicUrlPlaceholder' => $this->showPublicUrlPlaceholder(),
            'isExited' => str($this->database->status)->contains('exited'),
        ]);
    }
}
