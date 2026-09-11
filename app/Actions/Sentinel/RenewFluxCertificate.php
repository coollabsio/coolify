<?php

namespace App\Actions\Sentinel;

use App\Models\FluxCertificate;
use App\Models\Server;
use Closure;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

class RenewFluxCertificate
{
    use AsAction;

    /**
     * @param  Closure(FluxCertificate): void|null  $restartAndValidate
     */
    public function handle(?Closure $restartAndValidate = null): bool
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
            if ($previous->valid_until->isAfter(now()->addDays(config('constants.flux.renew_before_days')))) {
                return false;
            }
            if ($previous->certificateAuthority->state !== 'active') {
                throw new RuntimeException('Flux renewal requires the current installation CA.');
            }

            $candidate = $previous->getConnection()->transaction(function () use ($previous): FluxCertificate {
                $candidate = IssueFluxCertificate::run($previous->identities);
                if ($candidate->certificate_authority_id !== $previous->certificate_authority_id) {
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
                }
                $candidate->update(['state' => 'failed']);
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
        $server = Server::findOrFail(0);
        instant_remote_process(['docker restart coolify-flux'], $server, timeout: 30);
        $directory = rtrim(config('constants.coolify.base_config_path'), '/').'/flux/pki';
        $identity = $certificate->identities[0];
        $verification = filter_var($identity, FILTER_VALIDATE_IP) !== false ? '-verify_ip' : '-verify_hostname';
        $port = (int) config('constants.flux.port');
        $command = 'timeout 15 openssl s_client -connect '.escapeshellarg('127.0.0.1:'.$port)
            .' -CAfile '.escapeshellarg($directory.'/ca.pem')
            .' -verify_return_error '.$verification.' '.escapeshellarg($identity)
            .' -servername '.escapeshellarg($identity).' </dev/null';

        retry(5, function () use ($server, $command, $certificate): void {
            $output = instant_remote_process([$command], $server, timeout: 20);
            if (! preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $output ?? '', $matches)
                || openssl_x509_fingerprint($matches[0], 'sha256') !== $certificate->fingerprint) {
                throw new RuntimeException('Flux did not serve the expected TLS certificate.');
            }
        }, 200);
    }
}
