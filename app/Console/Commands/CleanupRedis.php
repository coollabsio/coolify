<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class CleanupRedis extends Command
{
    protected $signature = 'cleanup:redis';

    protected $description = 'Cleanup Redis';

    public function handle()
    {
        $prefix = config('database.redis.options.prefix');

        $keys = Redis::connection()->keys('*:laravel*');
        collect($keys)->each(function ($key) use ($prefix) {
            $keyWithoutPrefix = str_replace($prefix, '', $key);
            Redis::connection()->del($keyWithoutPrefix);
        });

        $queueOverlaps = Redis::connection()->keys('*laravel-queue-overlap*');
        collect($queueOverlaps)->each(function ($key) {
            Redis::connection()->del($key);
        });
    }
}
