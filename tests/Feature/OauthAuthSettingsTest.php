<?php

use App\Actions\Fortify\CreateNewUser;
use App\Livewire\Settings\Advanced;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::create(['id' => 0]);
});

test('password login can be disabled for oauth only instances', function () {
    instanceSettings()->update(['is_password_auth_disabled' => true]);
    Once::flush();

    User::factory()->create([
        'id' => 0,
        'email' => 'test@example.com',
        'password' => Hash::make('password'),
    ]);

    $this->post('/login', [
        'email' => 'test@example.com',
        'password' => 'password',
    ])->assertSessionHasErrors();

    $this->assertGuest();
});

test('password registration is unavailable when oauth only login is enabled', function () {
    instanceSettings()->update([
        'is_registration_enabled' => true,
        'is_password_auth_disabled' => true,
    ]);
    Once::flush();

    $this->get('/register')->assertRedirect(route('login'));

    app(CreateNewUser::class)->create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'Password1!@',
        'password_confirmation' => 'Password1!@',
    ]);
})->throws(HttpException::class);

test('advanced settings can enable oauth registration separately from password registration', function () {
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);

    $this->actingAs($user);
    session(['currentTeam' => ['id' => $rootTeam->id]]);

    Livewire::test(Advanced::class)
        ->set('is_registration_enabled', false)
        ->set('is_oauth_registration_enabled', true)
        ->set('is_password_auth_disabled', true)
        ->call('instantSave')
        ->assertDispatched('success');

    $settings = instanceSettings()->fresh();

    expect($settings->is_registration_enabled)->toBeFalse()
        ->and($settings->is_oauth_registration_enabled)->toBeTrue()
        ->and($settings->is_password_auth_disabled)->toBeTrue();
});
