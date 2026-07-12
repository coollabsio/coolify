<?php

use App\Http\Middleware\CheckForcePasswordReset;
use App\Http\Middleware\DecideWhatToDoWithUser;
use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
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

describe('email verification hash', function () {
    test('sha256 hash is accepted and marks the user verified', function () {
        $user = User::factory()->create([
            'email' => 'verify-me@example.com',
            'email_verified_at' => null,
        ]);

        $url = URL::temporarySignedRoute('verify.verify', now()->addHour(), [
            'id' => $user->getKey(),
            'hash' => hash('sha256', $user->getEmailForVerification()),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect();

        $user->refresh();
        expect($user->email_verified_at)->not->toBeNull();
    });

    test('legacy sha1 hash is rejected', function () {
        $user = User::factory()->create([
            'email' => 'legacy-sha1@example.com',
            'email_verified_at' => null,
        ]);

        $url = URL::temporarySignedRoute('verify.verify', now()->addHour(), [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->actingAs($user)->get($url)->assertStatus(403);

        $user->refresh();
        expect($user->email_verified_at)->toBeNull();
    });

    test('tampered signature is rejected', function () {
        $user = User::factory()->create([
            'email' => 'tampered@example.com',
            'email_verified_at' => null,
        ]);

        $url = URL::temporarySignedRoute('verify.verify', now()->addHour(), [
            'id' => $user->getKey(),
            'hash' => hash('sha256', $user->getEmailForVerification()),
        ]);

        $tampered = $url.'x';

        $this->actingAs($user)->get($tampered)->assertStatus(403);
    });
});
