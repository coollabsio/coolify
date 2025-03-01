<?php

namespace App\Livewire\Server;

use App\Helpers\SslHelper;
use App\Jobs\RegenerateSslCertJob;
use App\Models\Server;
use App\Models\SslCertificate;
use Carbon\Carbon;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Advanced extends Component
{
    public Server $server;

    public ?SslCertificate $caCertificate = null;

    public $showCertificate = false;

    public $certificateContent = '';

    public ?Carbon $certificateValidUntil = null;

    public array $parameters = [];

    #[Validate(['string'])]
    public string $serverDiskUsageCheckFrequency = '0 23 * * *';

    #[Validate(['integer', 'min:1', 'max:99'])]
    public int $serverDiskUsageNotificationThreshold = 50;

    #[Validate(['integer', 'min:1'])]
    public int $concurrentBuilds = 1;

    #[Validate(['integer', 'min:1'])]
    public int $dynamicTimeout = 1;

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            $this->parameters = get_route_parameters();
            $this->syncData();
            $this->loadCaCertificate();
        } catch (\Throwable) {
            return redirect()->route('server.index');
        }
    }

    public function loadCaCertificate()
    {
        $this->caCertificate = SslCertificate::where('server_id', $this->server->id)->where('is_ca_certificate', true)->first();

        if ($this->caCertificate) {
            $this->certificateContent = $this->caCertificate->ssl_certificate;
            $this->certificateValidUntil = $this->caCertificate->valid_until;
        }
    }

    public function toggleCertificate()
    {
        $this->showCertificate = ! $this->showCertificate;
    }

    public function saveCaCertificate()
    {
        try {
            if (! $this->certificateContent) {
                throw new \Exception('Certificate content cannot be empty.');
            }

            if (! openssl_x509_read($this->certificateContent)) {
                throw new \Exception('Invalid certificate format.');
            }

            if ($this->caCertificate) {
                $this->caCertificate->ssl_certificate = $this->certificateContent;
                $this->caCertificate->save();

                $this->loadCaCertificate();

                $this->writeCertificateToServer();

                dispatch(new RegenerateSslCertJob(
                    server_id: $this->server->id,
                    force_regeneration: true
                ));
            }
            $this->dispatch('success', 'CA Certificate saved successfully.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function regenerateCaCertificate()
    {
        try {
            SslHelper::generateSslCertificate(
                commonName: 'Coolify CA Certificate',
                serverId: $this->server->id,
                isCaCertificate: true,
                validityDays: 10 * 365
            );

            $this->loadCaCertificate();

            $this->writeCertificateToServer();

            dispatch(new RegenerateSslCertJob(
                server_id: $this->server->id,
                force_regeneration: true
            ));

            $this->loadCaCertificate();
            $this->dispatch('success', 'CA Certificate regenerated successfully.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function writeCertificateToServer()
    {
        $caCertPath = config('constants.coolify.base_config_path').'/ssl/';

        $commands = collect([
            "mkdir -p $caCertPath",
            "chown -R 9999:root $caCertPath",
            "chmod -R 700 $caCertPath",
            "rm -rf $caCertPath/coolify-ca.crt",
            "echo '{$this->certificateContent}' > $caCertPath/coolify-ca.crt",
            "chmod 644 $caCertPath/coolify-ca.crt",
        ]);

        remote_process($commands, $this->server);
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();
            $this->server->settings->concurrent_builds = $this->concurrentBuilds;
            $this->server->settings->dynamic_timeout = $this->dynamicTimeout;
            $this->server->settings->server_disk_usage_notification_threshold = $this->serverDiskUsageNotificationThreshold;
            $this->server->settings->server_disk_usage_check_frequency = $this->serverDiskUsageCheckFrequency;
            $this->server->settings->save();
        } else {
            $this->concurrentBuilds = $this->server->settings->concurrent_builds;
            $this->dynamicTimeout = $this->server->settings->dynamic_timeout;
            $this->serverDiskUsageNotificationThreshold = $this->server->settings->server_disk_usage_notification_threshold;
            $this->serverDiskUsageCheckFrequency = $this->server->settings->server_disk_usage_check_frequency;
        }
    }

    public function instantSave()
    {
        try {
            $this->syncData(true);
            $this->dispatch('success', 'Server updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            if (! validate_cron_expression($this->serverDiskUsageCheckFrequency)) {
                $this->serverDiskUsageCheckFrequency = $this->server->settings->getOriginal('server_disk_usage_check_frequency');
                throw new \Exception('Invalid Cron / Human expression for Disk Usage Check Frequency.');
            }
            $this->syncData(true);
            $this->dispatch('success', 'Server updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.advanced');
    }
}
