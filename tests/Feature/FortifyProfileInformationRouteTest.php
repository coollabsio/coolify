<?php

use App\Models\InstanceSettings;
use App\Models\OauthIdentity;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('does not let a user change the email through the Fortify profile route', function (bool $hasSsoIdentity) {
    $user = User::factory()->create(['email' => 'owner@example.com']);
    if ($hasSsoIdentity) {
        OauthIdentity::create([
            'user_id' => $user->id,
            'provider' => 'github',
            'issuer' => 'github',
            'provider_user_id' => '123',
            'email' => 'owner@example.com',
        ]);
    }

    $this->actingAs($user)
        ->withoutMiddleware(VerifyCsrfToken::class)
        ->put('/user/profile-information', ['name' => 'Changed', 'email' => 'attacker@example.com']);

    expect(Route::has('user-profile-information.update'))->toBeFalse()
        ->and($user->fresh()->email)->toBe('owner@example.com')
        ->and($user->fresh()->name)->not->toBe('Changed');
})->with([
    'SSO user' => [true],
    'password user' => [false],
]);
