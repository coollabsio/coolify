<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
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
            GithubAppSeeder::class,
            GitlabAppSeeder::class,
            ApplicationSeeder::class,
            DBSeeder::class,
            ServiceSeeder::class,
        ]);
    }
}
