<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class CleanupQueue extends Command
{
    protected $signature = 'cleanup:queue';

    protected $description = 'Cleanup Queue';

    public function handle()
    {
        echo "Running queue cleanup...\n";
        $prefix = config('database.redis.options.prefix');
        $keys = Redis::connection()->keys('*:laravel*');
        foreach ($keys as $key) {
            $keyWithoutPrefix = str_replace($prefix, '', $key);
            Redis::connection()->del($keyWithoutPrefix);
        }
    }
}
