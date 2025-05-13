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
        $redis = Redis::connection('horizon');
        $keys = $redis->keys('*');
        $prefix = config('horizon.prefix');
        foreach ($keys as $key) {
            $keyWithoutPrefix = str_replace($prefix, '', $key);
            $type = $redis->command('type', [$keyWithoutPrefix]);

            if ($type === 5) {
                $data = $redis->command('hgetall', [$keyWithoutPrefix]);
                $status = data_get($data, 'status');
                if ($status === 'completed') {
                    $redis->command('del', [$keyWithoutPrefix]);
                }
            }
        }
    }
}
