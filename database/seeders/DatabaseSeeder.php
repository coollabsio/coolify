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
            PopulateSshKeysDirectorySeeder::class,
            ServerSeeder::class,
            ServerSettingSeeder::class,
            ProjectSeeder::class,
            StandaloneDockerSeeder::class,
            GithubAppSeeder::class,
            GitlabAppSeeder::class,
            ApplicationSeeder::class,
            ApplicationSettingsSeeder::class,
            LocalPersistentVolumeSeeder::class,
            S3StorageSeeder::class,
            StandalonePostgresqlSeeder::class,
            OauthSettingSeeder::class,
            DisableTwoStepConfirmationSeeder::class,
            SentinelSeeder::class,
            CaSslCertSeeder::class,
            PersonalAccessTokenSeeder::class,
        ]);
    }
}
