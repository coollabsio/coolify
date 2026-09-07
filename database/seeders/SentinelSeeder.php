<?php

namespace Database\Seeders;

use App\Models\Server;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class SentinelSeeder extends Seeder
{
    public function run()
    {
        Server::chunk(100, function ($servers) {
            foreach ($servers as $server) {
                try {
                    if (str($server->settings->sentinel_token)->isEmpty()) {
                        $server->settings->generateSentinelToken(ignoreEvent: true);
                    }
                    $developmentUrl = isDev() ? config('constants.sentinel.dev_url') : null;
                    if (filled($developmentUrl)) {
                        $server->settings->sentinel_custom_url = $developmentUrl;
                        $server->settings->saveQuietly();

                        continue;
                    }

                    if (str($server->settings->sentinel_custom_url)->isEmpty()) {
                        $url = $server->settings->generateSentinelUrl(ignoreEvent: true);
                        if (str($url)->isEmpty()) {
                            $server->settings->is_sentinel_enabled = false;
                            $server->settings->save();
                        }
                    }
                } catch (\Throwable $e) {
                    Log::error('Error seeding sentinel: '.$e->getMessage());
                }
            }
        });
    }
}
