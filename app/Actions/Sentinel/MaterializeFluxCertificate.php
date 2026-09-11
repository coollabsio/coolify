<?php

namespace App\Actions\Sentinel;

use App\Models\FluxCertificate;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

class MaterializeFluxCertificate
{
    use AsAction;

    public function handle(FluxCertificate $certificate): void
    {
        $authority = $certificate->certificateAuthority;
        if (! openssl_x509_check_private_key($certificate->certificate_pem, $certificate->private_key_pem)
            || openssl_x509_verify($certificate->certificate_pem, $authority->certificate_pem) !== 1) {
            throw new RuntimeException('The Flux certificate, key, and CA do not match.');
        }

        $directory = rtrim(config('constants.coolify.base_config_path'), '/').'/flux/pki';
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create the Flux certificate directory.');
        }
        $runtimeUid = (int) config('constants.flux.runtime_uid', 65532);
        if (! chmod($directory, 0700) || ! chown($directory, $runtimeUid)) {
            throw new RuntimeException('Cannot secure the Flux certificate directory.');
        }

        $files = [
            'ca.pem' => [$authority->certificate_pem, 0644],
            'server.pem' => [$certificate->certificate_pem, 0644],
            'server-key.pem' => [$certificate->private_key_pem, 0600],
        ];
        $temporaryFiles = [];
        try {
            foreach ($files as $name => [$contents, $mode]) {
                $temporary = tempnam($directory, '.flux-');
                if ($temporary === false) {
                    throw new RuntimeException('Cannot create a temporary Flux certificate file.');
                }
                $temporaryFiles[$name] = $temporary;
                if (dirname($temporary) !== $directory || ! chmod($temporary, 0600)) {
                    throw new RuntimeException('Cannot secure the temporary Flux certificate file.');
                }
                $stream = fopen($temporary, 'wb');
                if ($stream === false) {
                    throw new RuntimeException('Cannot open the temporary Flux certificate file.');
                }
                try {
                    if (fwrite($stream, $contents) !== strlen($contents) || ! fflush($stream) || ! fsync($stream)) {
                        throw new RuntimeException('Cannot write the Flux certificate file.');
                    }
                } finally {
                    fclose($stream);
                }
                if (! chmod($temporary, $mode)) {
                    throw new RuntimeException('Cannot set the Flux certificate file permissions.');
                }
                if (! chown($temporary, $runtimeUid)) {
                    throw new RuntimeException('Cannot set the Flux certificate file owner.');
                }
            }
            foreach ($temporaryFiles as $name => $temporary) {
                if (! rename($temporary, $directory.'/'.$name)) {
                    throw new RuntimeException('Cannot replace the Flux certificate file.');
                }
            }
        } finally {
            foreach ($temporaryFiles as $temporary) {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        }
    }

    /**
     * @return array<string, string>
     */
    public function retain(): array
    {
        $directory = rtrim(config('constants.coolify.base_config_path'), '/').'/flux/pki';
        $previousFiles = [];
        try {
            foreach (['ca.pem', 'server.pem', 'server-key.pem'] as $name) {
                $path = $directory.'/'.$name;
                if (! is_file($path)) {
                    throw new RuntimeException('The current Flux certificate files must exist before renewal.');
                }
                $backup = $directory.'/.previous-'.bin2hex(random_bytes(16)).'-'.$name;
                if (! link($path, $backup)) {
                    throw new RuntimeException('Cannot retain the current Flux certificate file.');
                }
                $previousFiles[$path] = $backup;
            }

            return $previousFiles;
        } catch (Throwable $exception) {
            $this->discard($previousFiles);
            throw $exception;
        }
    }

    /**
     * @param  array<string, string>  $previousFiles
     */
    public function restore(array $previousFiles): void
    {
        foreach ($previousFiles as $path => $backup) {
            if (! rename($backup, $path)) {
                throw new RuntimeException('Cannot restore the previous Flux certificate file.');
            }
            if (is_file($backup) && ! unlink($backup)) {
                throw new RuntimeException('Cannot remove the restored Flux certificate backup.');
            }
        }
    }

    /**
     * @param  array<string, string>  $previousFiles
     */
    public function discard(array $previousFiles): void
    {
        foreach ($previousFiles as $backup) {
            if (! unlink($backup)) {
                throw new RuntimeException('Cannot remove the previous Flux certificate file.');
            }
        }
    }
}
