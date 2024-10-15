<?php

namespace Database\Seeders;

use App\Models\Server;
use Illuminate\Database\Seeder;

class SentinelSeeder extends Seeder
{
    public function run()
    {
        try {
            Server::chunk(100, function ($servers) {
                foreach ($servers as $server) {
                    if (str($server->settings->sentinel_token)->isEmpty()) {
                        $server->generateSentinelToken();
                    }
                    if (str($server->settings->sentinel_custom_url)->isEmpty()) {
                        $url = $server->generateSentinelUrl();
                        logger()->info("Setting sentinel custom url for server {$server->id} to {$url}");
                        $server->settings->sentinel_custom_url = $url;
                        $server->settings->save();
                    }
                }
            });
        } catch (\Throwable $e) {
            echo "Error: {$e->getMessage()}\n";
            ray($e->getMessage());
        }
    }
}
