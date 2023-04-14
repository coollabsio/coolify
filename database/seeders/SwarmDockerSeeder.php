<?php

namespace Database\Seeders;

use App\Models\Destination;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SwarmDockerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $server_2 = Server::find(2);
        SwarmDocker::create([
            'id' => 1,
            'server_id' => $server_2->id,
        ]);
    }
}
