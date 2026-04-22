<?php

use App\Http\Middleware\CheckForcePasswordReset;
use App\Http\Middleware\DecideWhatToDoWithUser;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware([DecideWhatToDoWithUser::class, CheckForcePasswordReset::class]);
    Once::flush();
    if (! InstanceSettings::find(0)) {
        $settings = new InstanceSettings;
        $settings->id = 0;
        $settings->saveQuietly();
    }
});

describe('invitation link login', function () {
    test('does not auto-verify the email address', function () {
        $team = Team::factory()->create();
        $password = 'test-password-123';
        $user = User::factory()->create([
            'email' => 'invitee@example.com',
            'password' => Hash::make($password),
            'email_verified_at' => null,
        ]);
        $user->teams()->attach($team->id, ['role' => 'member']);

        $token = Crypt::encryptString("{$user->email}@@@{$password}");

        $this->get(route('auth.link', ['token' => $token]));

        $user->refresh();
        expect($user->email_verified_at)->toBeNull();
    });

    test('still logs the user in', function () {
        $team = Team::factory()->create();
        $password = 'test-password-123';
        $user = User::factory()->create([
            'email' => 'invitee2@example.com',
            'password' => Hash::make($password),
            'email_verified_at' => null,
        ]);
        $user->teams()->attach($team->id, ['role' => 'member']);

        $token = Crypt::encryptString("{$user->email}@@@{$password}");

        $this->get(route('auth.link', ['token' => $token]));

        expect(auth()->id())->toBe($user->id);
    });
});
