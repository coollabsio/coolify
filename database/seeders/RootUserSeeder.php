<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RootUserSeeder extends Seeder
{
    public function run(): void
    {
        // Only seed if we have the required environment variables
        if (env('ROOT_USER_EMAIL') && env('ROOT_USER_PASSWORD')) {
            User::updateOrCreate(
                ['id' => 0],
                [
                    'name' => env('ROOT_USER_NAME', 'Root User'),
                    'email' => env('ROOT_USER_EMAIL'),
                    'password' => Hash::make(env('ROOT_USER_PASSWORD')),
                ]
            );
            echo "  Root user created/updated successfully.\n";
        } else {
            echo "  Warning: ROOT_USER_EMAIL and ROOT_USER_PASSWORD environment variables are required for root user creation.\n";
        }
    }
}
