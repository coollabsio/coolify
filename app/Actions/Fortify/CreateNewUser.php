<?php

namespace App\Actions\Fortify;

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $settings = InstanceSettings::find(0);
        if (!$settings->is_registration_enabled) {
            Log::info('Registration is disabled');
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
            'password' => $this->passwordRules(),
        ])->validate();

        if (User::count() == 0) {
            // If this is the first user, make them the root user
            // Team is already created in the database/seeders/ProductionSeeder.php
            $team = Team::find(0);
            $user = User::create([
                'id' => 0,
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => Hash::make($input['password']),
                'is_root_user' => true,
            ]);
        } else {
            $team = Team::create([
                'name' => explode(' ', $input['name'], 2)[0] . "'s Team",
                'personal_team' => true,
            ]);
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => Hash::make($input['password']),
                'is_root_user' => false,
            ]);
        }

        // Add user to team
        DB::table('team_user')->insert([
            'user_id' => $user->id,
            'team_id' => $team->id,
            'role' => 'admin',
        ]);

        // Set session variable
        session(['currentTeam' => $user->currentTeam = $team]);
        return $user;
    }
}
