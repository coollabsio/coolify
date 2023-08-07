<?php

namespace Database\Seeders;

use App\Models\Environment;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            InstanceSettingsSeeder::class,
            UserSeeder::class,
            TeamSeeder::class,
            PrivateKeySeeder::class,
            ServerSeeder::class,
            ServerSettingSeeder::class,
            ProjectSeeder::class,
            ProjectSettingSeeder::class,
            EnvironmentSeeder::class,
            StandaloneDockerSeeder::class,
            SwarmDockerSeeder::class,
            KubernetesSeeder::class,
            GithubAppSeeder::class,
            GitlabAppSeeder::class,
            ApplicationSeeder::class,
            ApplicationSettingsSeeder::class,
            ApplicationPreviewSeeder::class,
            DBSeeder::class,
            ServiceSeeder::class,
            EnvironmentVariableSeeder::class,
            LocalPersistentVolumeSeeder::class,
            S3StorageSeeder::class,
            StandalonePostgresSeeder::class,
        ]);
    }
}