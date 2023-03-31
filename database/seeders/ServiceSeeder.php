<?php

namespace Database\Seeders;

use App\Models\Environment;
use App\Models\Service;
use App\Models\StandaloneDocker;
use Illuminate\Database\Seeder;

class ServiceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $environment_1 = Environment::find(1);
        $standalone_docker_1 = StandaloneDocker::find(1);
        // Service::create([
        //     'id' => 1,
        //     'name'=> "My first service",
        //     'environment_id' => $environment_1->id,
        //     'destination_id' => $standalone_docker_1->id,
        //     'destination_type' => StandaloneDocker::class,
        // ]);
    }
}
