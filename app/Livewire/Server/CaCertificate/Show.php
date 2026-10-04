<?php

namespace App\Livewire\Server\CaCertificate;

use App\Helpers\SslHelper;
use App\Jobs\RegenerateSslCertJob;
use App\Models\Server;
use App\Models\SslCertificate;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

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
        $this->caCertificate = $this->server->sslCertificates()->where('is_ca_certificate', true)->first();

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
            $this->authorize('manageCaCertificate', $this->server);
            if (! $this->certificateContent) {
                throw new \Exception('Certificate content cannot be empty.');
            }

            $parsedCert = openssl_x509_read($this->certificateContent);
            if (! $parsedCert) {
                throw new \Exception('Invalid certificate format.');
            }

            if (! openssl_x509_export($parsedCert, $cleanedCertificate)) {
                throw new \Exception('Failed to process certificate.');
            }
            $this->certificateContent = $cleanedCertificate;

            if ($this->caCertificate) {
                if (! openssl_x509_check_private_key($this->certificateContent, $this->caCertificate->ssl_private_key)) {
                    throw new \Exception('This certificate does not match the CA private key of this server. Coolify signs database certificates with that key, so clients would fail with a certificate signature error.');
                }

                $this->caCertificate->ssl_certificate = $this->certificateContent;
                $this->caCertificate->save();
                auditLog('ui.server.ca_certificate.updated', $this->auditContext());

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
            $this->authorize('manageCaCertificate', $this->server);
            SslHelper::generateSslCertificate(
                commonName: 'Coolify CA Certificate',
                serverId: $this->server->id,
                isCaCertificate: true,
                validityDays: 10 * 365
            );
            auditLog('ui.server.ca_certificate.regenerated', $this->auditContext());

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
        $commands = SslHelper::caCertificateFileCommands($this->certificateContent);

        remote_process($commands, $this->server);
    }

    /**
     * Identifies the server only. Certificate and key contents must never reach the audit log.
     *
     * @return array<string, mixed>
     */
    private function auditContext(): array
    {
        return [
            'team_id' => $this->server->team_id,
            'server_uuid' => $this->server->uuid,
            'server_name' => $this->server->name,
        ];
    }

    public function render()
    {
        return view('livewire.server.ca-certificate.show');
    }
}
