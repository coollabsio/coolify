<?php

namespace App\Livewire\Project\Database\Mysql;

use App\Models\StandaloneMysql;
use App\Traits\HasDatabaseStatusInfo;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class StatusInfo extends Component
{
    use AuthorizesRequests;
    use HasDatabaseStatusInfo;

    public StandaloneMysql $database;

    protected function databaseLabel(): string
    {
        return 'MySQL';
    }

    protected function sslModeOptions(): array
    {
        return [
            'PREFERRED' => ['title' => 'Prefer secure connections', 'label' => 'Prefer (secure)'],
            'REQUIRED' => ['title' => 'Require secure connections', 'label' => 'Require (secure)'],
            'VERIFY_CA' => ['title' => 'Verify CA certificate', 'label' => 'Verify CA (secure)'],
            'VERIFY_IDENTITY' => ['title' => 'Verify full certificate', 'label' => 'Verify Full (secure)'],
        ];
    }

    protected function sslModeHelper(): string
    {
        return 'Choose the SSL verification mode for MySQL connections';
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
