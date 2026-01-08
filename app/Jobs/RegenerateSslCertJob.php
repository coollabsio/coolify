<?php

namespace App\Jobs;

use App\Helpers\SSLHelper;
use App\Models\SslCertificate;
use App\Models\Team;
use App\Notifications\SslExpirationNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RegenerateSslCertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = 60;

    public function __construct(
        protected ?Team $team = null,
        protected ?int $server_id = null,
        protected bool $force_regeneration = false,
    ) {}

    public function handle()
    {
        $query = SslCertificate::query();

        if ($this->server_id) {
            $query->where('server_id', $this->server_id);
        }

        if (! $this->force_regeneration) {
            $query->where('valid_until', '<=', now()->addDays(14));
        }

        $query->where('is_ca_certificate', false);

        $regenerated = collect();

        $query->cursor()->each(function ($certificate) use ($regenerated) {
            try {
                $caCert = $certificate->server->sslCertificates()
                    ->where('is_ca_certificate', true)
                    ->first();

                if (! $caCert) {
                    Log::error("No CA certificate found for server_id: {$certificate->server_id}");

                    return;
                }
                SSLHelper::generateSslCertificate(
                    commonName: $certificate->common_name,
                    subjectAlternativeNames: $certificate->subject_alternative_names,
                    resourceType: $certificate->resource_type,
                    resourceId: $certificate->resource_id,
                    serverId: $certificate->server_id,
                    configurationDir: $certificate->configuration_dir,
                    mountPath: $certificate->mount_path,
                    caCert: $caCert->ssl_certificate,
                    caKey: $caCert->ssl_private_key,
                );
                $regenerated->push($certificate);
            } catch (\Exception $e) {
                Log::error('Failed to regenerate SSL certificate: '.$e->getMessage());
            }
        });

        if ($regenerated->isNotEmpty()) {
            $this->team?->notify(new SslExpirationNotification($regenerated));
        }
    }
}
