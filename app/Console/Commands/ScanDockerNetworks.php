<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\Docker\DockerNetworkScanner;
use Illuminate\Console\Command;

class ScanDockerNetworks extends Command
{
    protected $signature = 'coolify:docker-networks:scan {server_uuid}';

    protected $description = 'Scan Docker networks for a server without modifying Docker runtime';

    public function handle(DockerNetworkScanner $scanner): int
    {
        $server = Server::query()->where('uuid', $this->argument('server_uuid'))->first();

        if (! $server) {
            $this->error('Server not found.');

            return Command::FAILURE;
        }

        $this->info("Scanning Docker networks for server: {$server->name}");

        $result = $scanner->sync($server);

        foreach ($result->get('errors', []) as $error) {
            $this->error($error);
        }

        $this->line('Found networks: '.$result->get('found', 0));
        $this->line('Created records: '.$result->get('created', 0));
        $this->line('Updated records: '.$result->get('updated', 0));
        $this->line('Removed: '.$result->get('removed', 0));

        if (count($result->get('errors', [])) > 0) {
            return Command::FAILURE;
        }

        $this->info('Done.');

        return Command::SUCCESS;
    }
}
