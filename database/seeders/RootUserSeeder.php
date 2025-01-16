<?php

namespace Database\Seeders;

use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class RootUserSeeder extends Seeder
{
    public function run(): void
    {
        if (User::where('id', 0)->exists()) {
            echo "  Root user already exists. Skipping creation.\n";

            return;
        }

        if (env('ROOT_USER_EMAIL') && env('ROOT_USER_PASSWORD')) {
            $validator = Validator::make([
                'email' => env('ROOT_USER_EMAIL'),
                'username' => env('ROOT_USERNAME', 'Root User'),
                'password' => env('ROOT_USER_PASSWORD'),
            ], [
                'email' => ['required', 'email:rfc,dns', 'max:255'],
                'username' => ['required', 'string', 'min:3', 'max:255', 'regex:/^[\w\s-]+$/'],
                'password' => ['required', 'string', 'min:8', Password::min(8)->mixedCase()->letters()->numbers()->symbols()->uncompromised()],
            ]);

            if ($validator->fails()) {
                echo "  Error: Invalid ROOT User Environment Variables\n";
                foreach ($validator->errors()->all() as $error) {
                    echo "  → {$error}\n";
                }

                return;
            }

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
