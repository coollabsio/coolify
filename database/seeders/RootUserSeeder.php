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
        try {
            if (User::where('id', 0)->exists()) {
                echo "\n  INFO  Root user already exists. Skipping creation.\n\n";

                return;
            }

            if (! env('ROOT_USER_EMAIL') || ! env('ROOT_USER_PASSWORD')) {
                return;
            }

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
                echo "\n  ERROR  Invalid Root User Environment Variables\n";
                foreach ($validator->errors()->all() as $error) {
                    echo "  → {$error}\n";
                }
                echo "\n";

                return;
            }

            try {
                User::create([
                    'id' => 0,
                    'name' => env('ROOT_USERNAME', 'Root User'),
                    'email' => env('ROOT_USER_EMAIL'),
                    'password' => Hash::make(env('ROOT_USER_PASSWORD')),
                ]);
                echo "\n  SUCCESS  Root user created successfully.\n\n";
            } catch (\Exception $e) {
                echo "\n  ERROR  Failed to create root user: {$e->getMessage()}\n\n";

                return;
            }

            try {
                InstanceSettings::updateOrCreate(
                    ['id' => 0],
                    ['is_registration_enabled' => false]
                );
                echo "\n  SUCCESS  Registration has been disabled successfully.\n\n";
            } catch (\Exception $e) {
                echo "\n  ERROR  Failed to update instance settings: {$e->getMessage()}\n\n";
            }
        } catch (\Exception $e) {
            echo "\n  ERROR  An unexpected error occurred: {$e->getMessage()}\n\n";
        }
    }
}
