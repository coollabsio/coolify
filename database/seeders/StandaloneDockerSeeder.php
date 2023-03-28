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
        $server_3 = Server::find(3);
        StandaloneDocker::create([
            'id' => 1,
            'server_id' => $server_3->id,
        ]);
    }
}
