<?php

namespace Database\Seeders;

use App\Models\StandaloneDocker;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (env('APP_ENV') === 'local') {
            $this->call([
                UserSeeder::class,
                TeamSeeder::class,
                PrivateKeySeeder::class,
                ServerSeeder::class,
                ProjectSeeder::class,
                ProjectSettingSeeder::class,
                EnvironmentSeeder::class,
                StandaloneDockerSeeder::class,
                SwarmDockerSeeder::class,
                KubernetesSeeder::class,
                ApplicationSeeder::class,
                DBSeeder::class,
            ]);
        }
    }
}
