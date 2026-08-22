<?php

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('constants.coolify.self_hosted', false);
    config()->set('subscription.provider', 'stripe');

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create([
        'name' => 'Normal User',
        'email_verified_at' => now(),
    ]);
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

test('allowed paths for unsubscribed accounts include profile and appearance', function () {
    expect(allowedPathsForUnsubscribedAccounts())
        ->toContain('profile')
        ->toContain('profile/appearance');
});

test('unsubscribed cloud user can open profile page', function () {
    $this->get(route('profile'))
        ->assertSuccessful()
        ->assertSee('Profile');
});

test('unsubscribed cloud user can open appearance page', function () {
    $this->get(route('profile.appearance'))
        ->assertSuccessful()
        ->assertSee('Appearance');
});

test('unsubscribed cloud user is still redirected from workspace routes', function () {
    $this->get(route('dashboard'))
        ->assertRedirect(route('subscription.index'));
});

test('unsubscribed cloud user does not get the workspace page switcher on profile', function () {
    $response = $this->get(route('profile'));

    $response->assertSuccessful();
    // Static "Profile" crumb may remain; the switcher control and destinations must not.
    $response->assertDontSee('title="Switch page"', false);
    $response->assertDontSee('>Dashboard</span>', false);
    $response->assertDontSee('>Projects</span>', false);
    $response->assertDontSee(url('/projects'), false);
});

test('unsubscribed cloud sidebar shows subscription link at the top of the list', function () {
    $response = $this->get(route('profile'));

    $response->assertSuccessful();
    $html = $response->getContent();

    expect($html)
        ->toContain('title="Subscription"')
        ->toContain(route('subscription.index'))
        ->toContain('>Subscription</span>')
        ->toContain('Account')
        // No spacer pushing account actions to the bottom of an empty sidebar.
        ->not->toContain('hidden flex-1 lg:list-item');

    $accountPos = strpos($html, 'nav-section');
    $subscriptionPos = strpos($html, 'title="Subscription"');
    expect($accountPos)->not->toBeFalse()
        ->and($subscriptionPos)->not->toBeFalse()
        ->and($accountPos)->toBeLessThan($subscriptionPos);
});
