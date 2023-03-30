<?php

namespace Database\Seeders;

use App\Models\Database;
use App\Models\Environment;
use App\Models\StandaloneDocker;
use Illuminate\Database\Seeder;

class DBSeeder extends Seeder
{
    public function run(): void
    {
        $environment_1 = Environment::find(1);
        $standalone_docker_1 = StandaloneDocker::find(1);
        // Database::create([
        //     'id' => 1,
        //     'name'=> "My first database",
        //     'environment_id' => $environment_1->id,
        //     'destination_id' => $standalone_docker_1->id,
        //     'destination_type' => StandaloneDocker::class,
        // ]);
    }
}
