<?php

namespace Database\Seeders;

use App\Models\StandaloneDocker;
use Illuminate\Database\Seeder;

class StandaloneDockerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        StandaloneDocker::create([
            'id' => 0,
            'name' => 'Standalone Docker 1',
            'network' => 'coolify',
            'server_id' => 0,
        ]);
    }
}
