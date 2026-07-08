<?php

namespace App\Actions\Fortify;

use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    private const REGISTRATION_IP_MAX_ATTEMPTS = 3;

    private const REGISTRATION_IP_DECAY_SECONDS = 600;

    private const REGISTRATION_EMAIL_IDENTITY_MAX_ATTEMPTS = 3;

    private const REGISTRATION_EMAIL_IDENTITY_DECAY_SECONDS = 3600;

    public function __construct(private readonly Request $request) {}

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

        $this->ensureRegistrationIsNotRateLimited($input);

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
            $user = (new User)->forceFill([
                'id' => 0,
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => Hash::make($input['password']),
            ]);
            $user->save();
            $team = $user->teams()->first() ?? Team::find(0);
            if ($team !== null && ! $user->teams()->where('team_id', $team->id)->exists()) {
                $user->teams()->attach($team, ['role' => 'owner']);
            }

            // Disable registration after first user is created
            $settings = instanceSettings();
            $settings->is_registration_enabled = false;
            $settings->save();
        } else {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
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

    /**
     * @param  array<string, string>  $input
     */
    private function ensureRegistrationIsNotRateLimited(array $input): void
    {
        $keys = [
            [
                'key' => 'registration:ip:'.sha1($this->realIp()),
                'max' => self::REGISTRATION_IP_MAX_ATTEMPTS,
                'decay' => self::REGISTRATION_IP_DECAY_SECONDS,
            ],
        ];

        $emailIdentity = normalize_email_identity($input['email'] ?? null);
        if ($emailIdentity !== null) {
            $keys[] = [
                'key' => 'registration:email-identity:'.sha1($emailIdentity),
                'max' => self::REGISTRATION_EMAIL_IDENTITY_MAX_ATTEMPTS,
                'decay' => self::REGISTRATION_EMAIL_IDENTITY_DECAY_SECONDS,
            ];
        }

        foreach ($keys as $limit) {
            if (RateLimiter::tooManyAttempts($limit['key'], $limit['max'])) {
                abort(429, 'Too many registration attempts. Please try again later.');
            }
        }

        foreach ($keys as $limit) {
            RateLimiter::hit($limit['key'], $limit['decay']);
        }
    }

    private function realIp(): string
    {
        return $this->request->server('REMOTE_ADDR') ?? $this->request->ip();
    }
}
