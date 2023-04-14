<?php

namespace Database\Seeders;

use App\Models\Destination;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class StandaloneDockerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $server_0 = Server::find(0);
        StandaloneDocker::create([
            'id' => 1,
            'network' => 'coolify',
            'server_id' => $server_0->id,
        ]);
    }
}
