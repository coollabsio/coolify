<?php

namespace Database\Seeders;

use App\Models\Destination;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Illuminate\Database\Seeder;

class StandaloneDockerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $server_1 = Server::find(0);
        StandaloneDocker::create([
            'name' => 'Standalone Docker 1',
            'network' => 'coolify',
            'server_id' => $server_1->id,
        ]);
    }
}
