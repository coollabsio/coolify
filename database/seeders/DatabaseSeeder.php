<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (env('APP_ENV') === 'local') {
            $this->call([
                UserSeeder::class,
                TeamSeeder::class,
                ServerSeeder::class,
                PrivateKeySeeder::class,
                ProjectSeeder::class,
                ProjectSettingSeeder::class,
                EnvironmentSeeder::class,
                ApplicationSeeder::class,
                DBSeeder::class,
            ]);
        }
    }
}
