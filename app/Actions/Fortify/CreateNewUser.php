<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $settings = instanceSettings();
        if (! $settings->is_registration_enabled) {
            abort(403);
        }
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ])->validate();

        if (User::count() == 0) {
            // If this is the first user, make them the root user
            // Team is already created in the database/seeders/ProductionSeeder.php
            $user = User::create([
                'id' => 0,
                'name' => $input['name'],
                'email' => strtolower($input['email']),
                'password' => Hash::make($input['password']),
            ]);
            $team = $user->teams()->first();

            // Disable registration after first user is created
            $settings = instanceSettings();
            $settings->is_registration_enabled = false;
            $settings->save();
        } else {
            $user = User::create([
                'name' => $input['name'],
                'email' => strtolower($input['email']),
                'password' => Hash::make($input['password']),
            ]);
            $team = $user->teams()->first();
            if (isCloud()) {
                $user->sendVerificationEmail();
            } else {
                $user->markEmailAsVerified();
            }
        }
        // Set session variable
        session(['currentTeam' => $user->currentTeam = $team]);

        return $user;
    }
}
