<?php

namespace Database\Seeders;

use App\Models\StandaloneDocker;
use App\Models\StandaloneRedis;
use Illuminate\Database\Seeder;

class StandaloneRedisSeeder extends Seeder
{
    public function run(): void
    {
        StandaloneRedis::create([
            'name' => 'Local PostgreSQL',
            'description' => 'Local PostgreSQL for testing',
            'redis_password' => 'redis',
            'environment_id' => 1,
            'destination_id' => 0,
            'destination_type' => StandaloneDocker::class,
        ]);
    }
}
