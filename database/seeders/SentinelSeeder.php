<?php

namespace Database\Seeders;

use App\Jobs\CheckAndStartSentinelJob;
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
                    if ($server->isSentinelEnabled()) {
                        $server->settings->is_sentinel_enabled = true;
                        $server->settings->saveQuietly();
                    }
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
                        $server->settings->generateSentinelUrl(ignoreEvent: true);
                    }
                    if ($server->isFunctional() && $server->isSentinelEnabled() && filled($server->settings->sentinel_custom_url)) {
                        CheckAndStartSentinelJob::dispatch($server);
                    }
                } catch (\Throwable $e) {
                    Log::error('Error seeding sentinel: '.$e->getMessage());
                }
            }
        });
    }
}
