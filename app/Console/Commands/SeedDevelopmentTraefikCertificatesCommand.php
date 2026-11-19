<?php

namespace App\Console\Commands;

use App\Actions\Development\SeedDevelopmentTraefikCertificates;
use App\Models\Server;
use Illuminate\Console\Command;

class SeedDevelopmentTraefikCertificatesCommand extends Command
{
    protected $signature = 'dev:traefik-certificates {server=0 : Server id}';

    protected $description = 'Add example TLS certificates to the Traefik acme.json of a development server';

    public function handle(): int
    {
        if (! isDev()) {
            $this->error('This command may only run in development mode.');

            return self::FAILURE;
        }

        $server = Server::query()->findOrFail((int) $this->argument('server'));
        $count = SeedDevelopmentTraefikCertificates::run($server);
        $this->info("Added {$count} example certificates to {$server->name} under the ".SeedDevelopmentTraefikCertificates::RESOLVER.' resolver.');

        return self::SUCCESS;
    }
}
