<?php

use App\Livewire\Settings\Index;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('dashboard proxy keeps existing HTTPS redirect behavior by default', function () {
    $settings = new InstanceSettings;
    $server = new Server;

    expect($settings->is_dashboard_force_https_enabled)->toBeTrue()
        ->and($server->dashboardHttpMiddlewares($settings))->toBe(['redirect-to-https'])
        ->and($server->dashboardCaddySiteAddress($settings, 'https', 'dashboard.example.com'))
        ->toBe('https://dashboard.example.com');
});

test('dashboard proxy accepts HTTP and HTTPS when its redirect is disabled', function () {
    $settings = new InstanceSettings(['is_dashboard_force_https_enabled' => false]);
    $server = new Server;

    expect($server->dashboardHttpMiddlewares($settings))->toBe(['gzip'])
        ->and($server->dashboardCaddySiteAddress($settings, 'https', 'dashboard.example.com'))
        ->toBe('http://dashboard.example.com, https://dashboard.example.com')
        ->and($server->dashboardCaddySiteAddress($settings, 'http', 'dashboard.example.com'))
        ->toBe('http://dashboard.example.com');
});

test('instance administrators can configure the dashboard HTTPS redirect', function () {
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    Server::factory()->create(['id' => 0, 'team_id' => $rootTeam->id]);
    $settings = InstanceSettings::forceCreate([
        'id' => 0,
        'fqdn' => 'https://dashboard.example.com',
    ]);
    Once::flush();

    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);

    $this->actingAs($user);
    session(['currentTeam' => ['id' => $rootTeam->id]]);

    Livewire::test(Index::class)
        ->assertSet('is_dashboard_force_https_enabled', true)
        ->assertSee('Redirect HTTP to HTTPS')
        ->assertSee('Keep enabled when Cloudflare uses Full or Full (Strict) SSL.')
        ->set('is_dashboard_force_https_enabled', false)
        ->call('instantSave')
        ->assertHasNoErrors();

    expect($settings->fresh()->is_dashboard_force_https_enabled)->toBeFalse();
});

test('dashboard HTTPS redirect saves immediately when changed', function () {
    $contents = file_get_contents(resource_path('views/livewire/settings/index.blade.php'));

    expect($contents)
        ->toMatch('/id="is_dashboard_force_https_enabled"[\s\S]*?onChange="submit"/')
        ->and($contents)->not->toContain('targets="fqdn,is_dashboard_force_https_enabled');
});

test('dashboard HTTPS redirect is next to the URL on desktop', function () {
    $contents = file_get_contents(resource_path('views/livewire/settings/index.blade.php'));

    expect($contents)
        ->toContain("'lg:col-span-2' => !str_starts_with")
        ->not->toContain('<div class="lg:col-span-2 max-w-md">');
});

test('dashboard HTTPS redirect control is hidden for an HTTP URL', function () {
    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    Server::factory()->create(['id' => 0, 'team_id' => $rootTeam->id]);
    InstanceSettings::forceCreate([
        'id' => 0,
        'fqdn' => 'http://dashboard.example.com',
    ]);
    Once::flush();

    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);

    $this->actingAs($user);
    session(['currentTeam' => ['id' => $rootTeam->id]]);

    Livewire::test(Index::class)
        ->assertDontSee('Redirect HTTP to HTTPS');
});
