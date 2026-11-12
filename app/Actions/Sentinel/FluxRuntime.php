<?php

namespace App\Actions\Sentinel;

use App\Models\FluxCertificate;
use App\Models\InstanceSettings;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

/**
 * The Flux of the s6 service `flux` in the Coolify container (decision 0021).
 */
class FluxRuntime
{
    use AsAction;

    /** The gRPC port for Sentinel. The compose file publishes it. */
    public const LISTEN_PORT = 7443;

    /** The internal API for Coolify, on the loopback interface of the container only. */
    public const INTERNAL_LISTEN_ADDRESS = '127.0.0.1:7080';

    /**
     * Flux runs when Nodes are on or a Node exists, and Coolify uses the Flux of this container (not Coolify Cloud).
     */
    public static function shouldRun(): bool
    {
        return config('constants.flux.internal_url') === 'http://'.self::INTERNAL_LISTEN_ADDRESS && nodesInUse();
    }

    /**
     * Generates the secrets and the certificate for the Flux URL when they are missing, writes the certificate
     * files, and returns the environment of Flux. It never contains the private signing key.
     *
     * @return array<string, string>
     */
    public function handle(): array
    {
        InstanceSettings::ensureFluxSecrets();

        $identities = (new ResolveFluxPublicUrl)->certificateIdentities();
        $directory = rtrim(config('constants.coolify.base_config_path'), '/').'/flux/pki';
        $certificate = FluxCertificate::query()->where('state', 'active')->first();
        if ($certificate === null) {
            $certificate = InitializeFluxTls::run($identities);
        } elseif (! $this->servesKnownCertificate($directory.'/server.pem')) {
            // A new container has no files yet. During a renewal, the files hold the pending certificate: keep them.
            MaterializeFluxCertificate::run($certificate);
        }
        if (! in_array($identities[0], $certificate->identities, true)) {
            // Flux is not running yet, so the new certificate needs no restart. Flux starts with the old one on failure.
            try {
                RenewFluxCertificate::run(fn () => null, true, null, $identities);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $environment = [
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'RUST_LOG' => getenv('RUST_LOG') ?: null,
            'FLUX_LISTEN_ADDR' => '0.0.0.0:'.self::LISTEN_PORT,
            'FLUX_INTERNAL_LISTEN_ADDR' => self::INTERNAL_LISTEN_ADDRESS,
            'FLUX_INTERNAL_EVENTS_URL' => 'http://127.0.0.1:8080/api/v1/internal/sentinel/control/events',
            'FLUX_INTERNAL_TOKEN' => config('constants.flux.internal_token'),
            'FLUX_ISSUER' => config('constants.flux.issuer'),
            'FLUX_SIGNING_KEY_ID' => config('constants.flux.signing_key_id'),
            'FLUX_SIGNING_PUBLIC_KEY' => config('constants.flux.signing_public_key'),
            'FLUX_PREVIOUS_SIGNING_KEY_ID' => config('constants.flux.previous_signing_key_id'),
            'FLUX_PREVIOUS_SIGNING_PUBLIC_KEY' => config('constants.flux.previous_signing_public_key'),
            'FLUX_TLS_CERT_PATH' => $directory.'/server.pem',
            'FLUX_TLS_KEY_PATH' => $directory.'/server-key.pem',
        ];
        foreach (['FLUX_INTERNAL_TOKEN', 'FLUX_ISSUER', 'FLUX_SIGNING_KEY_ID', 'FLUX_SIGNING_PUBLIC_KEY'] as $required) {
            if (blank($environment[$required])) {
                throw new RuntimeException("Flux cannot start without {$required}.");
            }
        }

        return array_map(strval(...), array_filter($environment, filled(...)));
    }

    private function servesKnownCertificate(string $path): bool
    {
        $pem = is_file($path) ? file_get_contents($path) : false;
        $fingerprint = $pem === false ? false : openssl_x509_fingerprint($pem, 'sha256');

        return $fingerprint !== false
            && FluxCertificate::query()->whereIn('state', ['active', 'pending'])->where('fingerprint', $fingerprint)->exists();
    }
}
