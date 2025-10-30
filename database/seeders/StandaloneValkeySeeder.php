<?php

namespace Database\Seeders;

use App\Models\StandaloneDocker;
use App\Models\StandaloneValkey;
use Illuminate\Database\Seeder;

class StandaloneValkeySeeder extends Seeder
{
    public function run(): void
    {
        StandaloneValkey::create([
            'name' => 'Local Valkey',
            'description' => 'Local Valkey for testing',
            'valkey_password' => 'valkey',
            'environment_id' => 1,
            'destination_id' => 0,
            'destination_type' => StandaloneDocker::class,
        ]);
    }
}
