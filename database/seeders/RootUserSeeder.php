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
        if (User::where('id', 0)->exists()) {
            echo "  Root user already exists. Skipping creation.\n";

            return;
        }

        if (env('ROOT_USER_EMAIL') && env('ROOT_USER_PASSWORD')) {
            User::create([
                'id' => 0,
                'name' => env('ROOT_USERNAME', 'Root User'),
                'email' => env('ROOT_USER_EMAIL'),
                'password' => Hash::make(env('ROOT_USER_PASSWORD')),
            ]);

            InstanceSettings::updateOrCreate(
                ['id' => 0],
                ['is_registration_enabled' => false]
            );

            echo "  Root user created successfully.\n";
            echo "  Registration has been disabled.\n";
        } else {
            echo "  Warning: ROOT_USER_EMAIL and ROOT_USER_PASSWORD environment variables are required for root user creation.\n";
        }
    }
}
