<?php

namespace App\Livewire\Project\Database\Postgresql;

use App\Models\StandalonePostgresql;
use App\Traits\HasDatabaseStatusInfo;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class StatusInfo extends Component
{
    use AuthorizesRequests;
    use HasDatabaseStatusInfo;

    public StandalonePostgresql $database;

    protected function databaseLabel(): string
    {
        return 'Postgres';
    }

    protected function sslModeOptions(): array
    {
        return [
            'allow' => ['title' => 'Allow insecure connections', 'label' => 'allow (insecure)'],
            'prefer' => ['title' => 'Prefer secure connections', 'label' => 'prefer (secure)'],
            'require' => ['title' => 'Require secure connections', 'label' => 'require (secure)'],
            'verify-ca' => ['title' => 'Verify CA certificate', 'label' => 'verify-ca (secure)'],
            'verify-full' => ['title' => 'Verify full certificate', 'label' => 'verify-full (secure)'],
        ];
    }

    protected function sslModeHelper(): string
    {
        return 'Choose the SSL verification mode for PostgreSQL connections';
    }

    protected function afterRefresh(): void
    {
        $this->sslMode = $this->database->ssl_mode;
    }

    protected function applyExtraSslAttributes(): void
    {
        $this->database->ssl_mode = $this->sslMode;
    }

    public function updatedSslMode(): void
    {
        $this->instantSaveSSL();
    }
}
