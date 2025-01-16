<?php

namespace Database\Seeders;

use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RootUserSeeder extends Seeder
{
    public function run(): void
    {
        if (env('ROOT_USER_EMAIL') && env('ROOT_USER_PASSWORD')) {
            User::updateOrCreate(
                ['id' => 0],
                [
                    'name' => env('ROOT_USER_NAME', 'Root User'),
                    'email' => env('ROOT_USER_EMAIL'),
                    'password' => Hash::make(env('ROOT_USER_PASSWORD')),
                ]
            );

            InstanceSettings::updateOrCreate(
                ['id' => 0],
                ['is_registration_enabled' => false]
            );

            echo "  Root user created/updated successfully.\n";
            echo "  Registration has been disabled.\n";
        } else {
            echo "  Warning: ROOT_USER_EMAIL and ROOT_USER_PASSWORD environment variables are required for root user creation.\n";
        }
    }
}
