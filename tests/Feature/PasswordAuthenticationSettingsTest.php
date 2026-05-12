<?php

use App\Http\Middleware\CheckForcePasswordReset;
use App\Http\Middleware\DecideWhatToDoWithUser;
use App\Livewire\Settings\Advanced;
use App\Livewire\SettingsOauth;
use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware([DecideWhatToDoWithUser::class, CheckForcePasswordReset::class]);
    $this->withoutVite();
    config()->set('app.maintenance.driver', 'file');
    Once::flush();
    Model::unguarded(function () {
        InstanceSettings::create([
            'id' => 0,
            'is_registration_enabled' => true,
            'is_oauth_registration_enabled' => false,
            'is_password_authentication_enabled' => false,
        ]);
    });
});

it('blocks password login when password authentication is disabled', function () {
    $user = User::factory()->create([
        'email' => 'password-user@example.com',
        'password' => Hash::make('password'),
    ]);

    $this->from('/login')->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect('/login');

    $this->assertGuest();
});

it('blocks password registration when password authentication is disabled', function () {
    User::factory()->create();

    $this->post('/register', [
        'name' => 'Password User',
        'email' => 'new-password-user@example.com',
        'password' => 'Password1!',
        'password_confirmation' => 'Password1!',
    ])->assertForbidden();

    expect(User::whereEmail('new-password-user@example.com')->exists())->toBeFalse();
});

it('allows the first root user to register with a password even when password authentication is disabled', function () {
    $this->get('/register')->assertOk();

    $this->post('/register', [
        'name' => 'Root User',
        'email' => 'root-user@example.com',
        'password' => 'Password1!',
        'password_confirmation' => 'Password1!',
    ])->assertRedirect(route('settings.index'));

    $user = User::whereEmail('root-user@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->id)->toBe(0)
        ->and($user->password)->not->toBeNull();
});

it('keeps password authentication enabled when no oauth provider is enabled', function () {
    InstanceSettings::find(0)->update([
        'is_password_authentication_enabled' => true,
    ]);
    Once::flush();

    Model::unguarded(function () {
        $rootTeam = Team::factory()->create([
            'id' => 0,
            'name' => 'Root Team',
            'personal_team' => false,
        ]);
        $admin = User::factory()->create();
        $rootTeam->members()->attach($admin->id, ['role' => 'admin']);

        $this->actingAs($admin);
        session(['currentTeam' => ['id' => $rootTeam->id]]);
    });

    Livewire::test(Advanced::class)
        ->set('is_password_authentication_enabled', false)
        ->call('instantSave')
        ->assertDispatched('error');

    expect(InstanceSettings::find(0)->is_password_authentication_enabled)->toBeTrue();
});

it('keeps the last oauth provider enabled when password authentication is disabled', function () {
    OauthSetting::create([
        'provider' => 'github',
        'enabled' => true,
        'client_id' => 'github-client-id',
        'client_secret' => 'github-client-secret',
    ]);

    Model::unguarded(function () {
        $rootTeam = Team::factory()->create([
            'id' => 0,
            'name' => 'Root Team',
            'personal_team' => false,
        ]);
        $admin = User::factory()->create();
        $rootTeam->members()->attach($admin->id, ['role' => 'admin']);

        $this->actingAs($admin);
        session(['currentTeam' => ['id' => $rootTeam->id]]);
    });

    Livewire::test(SettingsOauth::class)
        ->set('oauth_settings_map.github.enabled', false)
        ->call('instantSave', 'github')
        ->assertDispatched('error');

    expect((bool) OauthSetting::where('provider', 'github')->first()->enabled)->toBeTrue();
});
