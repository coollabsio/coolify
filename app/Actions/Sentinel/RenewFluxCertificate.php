<?php

namespace App\Actions\Sentinel;

use App\Models\FluxCertificate;
use App\Models\FluxCertificateAuthority;
use Closure;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

class RenewFluxCertificate
{
    use AsAction;

    /**
     * @param  Closure(FluxCertificate): void|null  $restartAndValidate
     * @param  FluxCertificateAuthority|null  $authority  A CA rotation switch passes the new CA; renewal otherwise keeps the active CA.
     * @param  list<string>|null  $identities  A change of the Flux URL passes the new names; renewal otherwise keeps them.
     */
    public function handle(?Closure $restartAndValidate = null, bool $force = false, ?FluxCertificateAuthority $authority = null, ?array $identities = null): bool
    {
        $directory = rtrim(config('constants.coolify.base_config_path'), '/').'/flux';
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create the Flux directory.');
        }
        $lock = fopen($directory.'/.certificate-renewal.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open the Flux certificate renewal lock.');
        }
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return false;
        }

        try {
            $active = FluxCertificate::query()->where('state', 'active')->get();
            if ($active->isEmpty()) {
                return false;
            }
            $previous = $active->sole();
            if ($authority === null && ! $force && $previous->valid_until->isAfter(now()->addDays(config('constants.flux.renew_before_days')))) {
                return false;
            }
            if ($authority === null && $previous->certificateAuthority->state !== FluxCertificateAuthority::STATE_ACTIVE) {
                throw new RuntimeException('Flux renewal requires the current installation CA.');
            }

            $candidate = $previous->getConnection()->transaction(function () use ($previous, $authority, $identities): FluxCertificate {
                $candidate = IssueFluxCertificate::run($identities ?? $previous->identities, $authority);
                $expectedAuthorityId = $authority->id ?? $previous->certificate_authority_id;
                if ($candidate->certificate_authority_id !== $expectedAuthorityId) {
                    throw new RuntimeException('Flux renewal must not change the installation CA.');
                }
                $candidate->update(['state' => 'pending', 'version' => $previous->version + 1]);

                return $candidate;
            });
            $restartAndValidate ??= $this->restartAndValidate(...);
            $materializer = MaterializeFluxCertificate::make();
            $previousFiles = [];
            try {
                $previousFiles = $materializer->retain();
                $materializer->handle($candidate);
                $restartAndValidate($candidate);
                $previous->getConnection()->transaction(function () use ($previous, $candidate): void {
                    $previous->update(['state' => 'previous']);
                    $candidate->update(['state' => 'active']);
                });
            } catch (Throwable $exception) {
                try {
                    if ($previousFiles !== []) {
                        $materializer->restore($previousFiles);
                        $restartAndValidate($previous);
                    }
                } catch (Throwable $rollbackException) {
                    throw new RuntimeException('Flux certificate renewal failed and rollback failed.', previous: $rollbackException);
                } finally {
                    $candidate->update(['state' => 'failed']);
                }
                throw $exception;
            }

            $materializer->discard($previousFiles);

            return true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function restartAndValidate(FluxCertificate $certificate): void
    {
        RestartFlux::run();
        $caFile = rtrim(config('constants.coolify.base_config_path'), '/').'/flux/pki/ca.pem';
        $identity = $certificate->identities[0];

        retry(30, function () use ($caFile, $identity, $certificate): void {
            $context = stream_context_create(['ssl' => [
                'capture_peer_cert' => true,
                'cafile' => $caFile,
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $identity,
            ]]);
            $client = @stream_socket_client('ssl://127.0.0.1:'.FluxRuntime::LISTEN_PORT, $errorCode, $errorMessage, 5, STREAM_CLIENT_CONNECT, $context);
            if ($client === false) {
                throw new RuntimeException("Flux TLS is not reachable: {$errorMessage}");
            }
            $peer = stream_context_get_params($client)['options']['ssl']['peer_certificate'] ?? null;
            fclose($client);
            if ($peer === null || openssl_x509_fingerprint($peer, 'sha256') !== $certificate->fingerprint) {
                throw new RuntimeException('Flux did not serve the expected TLS certificate.');
            }
        }, 500);
    }
}
