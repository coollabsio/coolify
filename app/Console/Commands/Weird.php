<?php

namespace App\Console\Commands;

use App\Actions\Server\ServerCheck;
use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
use App\Models\Server;
use Illuminate\Console\Command;
use Str;

class Weird extends Command
{
    protected $signature = 'weird {--number=1} {--run}';

    protected $description = 'Weird stuff';

    public function handle()
    {
        try {
            if (! isDev()) {
                $this->error('This command can only be run in development mode');

                return;
            }
            $run = $this->option('run');
            if ($run) {
                $servers = Server::all();
                foreach ($servers as $server) {
                    ServerCheck::dispatch($server);
                }

                return;
            }
            $number = $this->option('number');
            for ($i = 0; $i < $number; $i++) {
                $uuid = Str::uuid();
                $server = Server::create([
                    'name' => 'localhost-'.$uuid,
                    'description' => 'This is a test docker container in development mode',
                    'ip' => 'coolify-testing-host',
                    'team_id' => 0,
                    'private_key_id' => 1,
                    'proxy' => [
                        'type' => ProxyTypes::NONE->value,
                        'status' => ProxyStatus::EXITED->value,
                    ],
                ]);
                $server->settings->update([
                    'is_usable' => true,
                    'is_reachable' => true,
                ]);
            }
        } catch (\Exception $e) {
            $this->error($e->getMessage());
        }
    }
}
