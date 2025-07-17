<?php

namespace App\Livewire\Server\CaCertificate;

use App\Helpers\SslHelper;
use App\Jobs\RegenerateSslCertJob;
use App\Models\Server;
use App\Models\SslCertificate;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    #[Locked]
    public Server $server;

    public ?SslCertificate $caCertificate = null;

    public $showCertificate = false;

    public $certificateContent = '';

    public ?Carbon $certificateValidUntil = null;

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            $this->loadCaCertificate();
        } catch (\Throwable $e) {
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

    public function render()
    {
        return view('livewire.server.ca-certificate.show');
    }
}
