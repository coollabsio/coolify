<?php

namespace Database\Seeders;

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
            EnvironmentVariableSeeder::class,
            LocalPersistentVolumeSeeder::class,
            S3StorageSeeder::class,
            StandalonePostgresqlSeeder::class,
            ScheduledDatabaseBackupSeeder::class,
            ScheduledDatabaseBackupExecutionSeeder::class,
            OauthSettingSeeder::class,
        ]);
    }
}
