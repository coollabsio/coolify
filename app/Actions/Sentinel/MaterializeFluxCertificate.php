<?php

namespace App\Actions\Sentinel;

use App\Models\FluxCertificate;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

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
}
