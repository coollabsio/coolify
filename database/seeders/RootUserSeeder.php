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
                echo "  INFO  Root user already exists. Skipping creation.\n";

                return;
            }

            if (! env('ROOT_USER_EMAIL') || ! env('ROOT_USER_PASSWORD')) {
                echo "  ERROR  ROOT_USER_EMAIL and ROOT_USER_PASSWORD environment variables are required for root user creation.\n";

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
                echo "  ERROR  Invalid Root User Environment Variables\n";
                foreach ($validator->errors()->all() as $error) {
                    echo "  → {$error}\n";
                }

                return;
            }

            try {
                User::create([
                    'id' => 0,
                    'name' => env('ROOT_USERNAME', 'Root User'),
                    'email' => env('ROOT_USER_EMAIL'),
                    'password' => Hash::make(env('ROOT_USER_PASSWORD')),
                ]);
                echo "  SUCCESS  Root user created successfully.\n";
            } catch (\Exception $e) {
                echo "  ERROR  Failed to create root user: {$e->getMessage()}\n";

                return;
            }

            try {
                InstanceSettings::updateOrCreate(
                    ['id' => 0],
                    ['is_registration_enabled' => false]
                );
                echo "  SUCCESS  Registration has been disabled.\n";
            } catch (\Exception $e) {
                echo "  ERROR  Failed to update instance settings: {$e->getMessage()}\n";
            }
        } catch (\Exception $e) {
            echo "  ERROR  An unexpected error occurred: {$e->getMessage()}\n";
        }
    }
}
