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
                $existingCert = SslCertificate::where('server_id', $server->id)->first();

                if (! $existingCert) {
                    $serverCert = SslHelper::generateSslCertificate(
                        commonName: 'Coolify CA Certificate',
                        serverId: $server->id,
                        validityDays: 15 * 365
                    );

                    $serverCertPath = config('constants.coolify.base_config_path').'/ca/';

                    $commands = collect([
                        "mkdir -p $serverCertPath",
                        "chown -R 9999:root $serverCertPath",
                        "chmod -R 700 $serverCertPath",
                        "echo '{$serverCert->ssl_certificate}' > $serverCertPath/coolify-ca.crt",
                        "chmod 644 $serverCertPath/coolify-ca.crt",
                    ]);

                    remote_process($commands, $server);
                }
            }
        });
    }
}
