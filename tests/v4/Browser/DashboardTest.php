<?php

use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::create(['id' => 0]);

    $this->user = User::factory()->create([
        'id' => 0,
        'name' => 'Root User',
        'email' => 'test@example.com',
        'password' => Hash::make('password'),
    ]);

    PrivateKey::create([
        'id' => 1,
        'uuid' => 'ssh-test',
        'team_id' => 0,
        'name' => 'Test Key',
        'description' => 'Test SSH key',
        'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----',
    ]);

    Server::create([
        'id' => 0,
        'uuid' => 'localhost',
        'name' => 'localhost',
        'description' => 'This is a test docker container in development mode',
        'ip' => 'coolify-testing-host',
        'team_id' => 0,
        'private_key_id' => 1,
        'proxy' => [
            'type' => ProxyTypes::TRAEFIK->value,
            'status' => ProxyStatus::EXITED->value,
        ],
    ]);

    Server::create([
        'uuid' => 'production-1',
        'name' => 'production-web',
        'description' => 'Production web server cluster',
        'ip' => '10.0.0.1',
        'team_id' => 0,
        'private_key_id' => 1,
        'proxy' => [
            'type' => ProxyTypes::TRAEFIK->value,
            'status' => ProxyStatus::EXITED->value,
        ],
    ]);

    Server::create([
        'uuid' => 'staging-1',
        'name' => 'staging-server',
        'description' => 'Staging environment server',
        'ip' => '10.0.0.2',
        'team_id' => 0,
        'private_key_id' => 1,
        'proxy' => [
            'type' => ProxyTypes::TRAEFIK->value,
            'status' => ProxyStatus::EXITED->value,
        ],
    ]);

    Project::create([
        'uuid' => 'project-1',
        'name' => 'My first project',
        'description' => 'This is a test project in development',
        'team_id' => 0,
    ]);

    Project::create([
        'uuid' => 'project-2',
        'name' => 'Production API',
        'description' => 'Backend services for production',
        'team_id' => 0,
    ]);

    Project::create([
        'uuid' => 'project-3',
        'name' => 'Staging Environment',
        'description' => 'Staging and QA testing',
        'team_id' => 0,
    ]);
});

function loginAndSkipOnboarding(): mixed
{
    return visit('/login')
        ->fill('email', 'test@example.com')
        ->fill('password', 'password')
        ->click('Login')
        ->click('Skip Setup');
}

it('redirects to login when not authenticated', function () {
    $page = visit('/');

    $page->assertPathIs('/login')
        ->screenshot();
});

it('shows onboarding after first login', function () {
    $page = visit('/login');

    $page->fill('email', 'test@example.com')
        ->fill('password', 'password')
        ->click('Login')
        ->assertSee('Welcome to Coolify')
        ->assertSee("Let's go!")
        ->assertSee('Skip Setup')
        ->screenshot();
});

it('shows dashboard after skipping onboarding', function () {
    $page = loginAndSkipOnboarding();

    $page->assertSee('Dashboard')
        ->assertSee('Your self-hosted infrastructure.')
        ->screenshot();
});

it('shows all projects on dashboard', function () {
    $page = loginAndSkipOnboarding();

    $page->assertSee('Projects')
        ->assertSee('My first project')
        ->assertSee('This is a test project in development')
        ->assertSee('Production API')
        ->assertSee('Backend services for production')
        ->assertSee('Staging Environment')
        ->assertSee('Staging and QA testing')
        ->screenshot();
});

it('shows servers on dashboard', function () {
    $page = loginAndSkipOnboarding();

    $page->assertSee('Servers')
        ->assertSee('localhost')
        ->assertSee('This is a test docker container in development mode')
        ->assertSee('production-web')
        ->assertSee('Production web server cluster')
        ->assertSee('staging-server')
        ->assertSee('Staging environment server')
        ->screenshot();
});
