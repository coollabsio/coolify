<?php

namespace Database\Seeders;

use App\Helpers\SslHelper;
use App\Models\Server;
use App\Models\SslCertificate;
use Illuminate\Database\Seeder;

class CaSslCertSeeder extends Seeder
{
    public function run()
    {
        Server::chunk(200, function ($servers) {
            foreach ($servers as $server) {
                $existingCaCert = SslCertificate::where('server_id', $server->id)->where('is_ca_certificate', true)->first();

                if (! $existingCaCert) {
                    $caCert = SslHelper::generateSslCertificate(
                        commonName: 'Coolify CA Certificate',
                        serverId: $server->id,
                        isCaCertificate: true,
                        validityDays: 10 * 365
                    );
                } else {
                    $caCert = $existingCaCert;
                }
                $caCertPath = config('constants.coolify.base_config_path').'/ssl/';

                $commands = collect([
                    "mkdir -p $caCertPath",
                    "chown -R 9999:root $caCertPath",
                    "chmod -R 700 $caCertPath",
                    "rm -rf $caCertPath/coolify-ca.crt",
                    "echo '{$caCert->ssl_certificate}' > $caCertPath/coolify-ca.crt",
                    "chmod 644 $caCertPath/coolify-ca.crt",
                ]);

                remote_process($commands, $server);
            }
        });
    }
}
