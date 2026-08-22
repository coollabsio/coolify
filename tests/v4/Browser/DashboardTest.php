<?php

use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->stack = seedBrowserResourceStack([
        'projectUuid' => 'project-1',
        'projectName' => 'My first project',
        'projectDescription' => 'This is a test project in development',
        'serverDescription' => 'This is a test docker container in development mode',
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

it('redirects to login when not authenticated', function () {
    $page = visit('/');

    $page->assertPathIs('/login')
        ->screenshot();
});

it('shows onboarding after first login', function () {
    // seedBrowserResourceStack disables boarding for most browser flows; re-enable for this case.
    Team::query()->whereKey(0)->update(['show_boarding' => true]);

    $page = visit('/login');

    $page->fill('email', 'test@example.com')
        ->fill('password', 'password')
        ->click('Login')
        ->wait(1.5)
        ->assertSee('Welcome to Coolify')
        ->assertSee('Continue')
        ->assertSee('Skip setup')
        ->screenshot(filename: 'dashboard-onboarding-after-login');
});

it('shows dashboard after skipping onboarding', function () {
    $page = loginAndSkipBoarding();

    $page->assertSee('Dashboard')
        ->assertSee('Projects')
        ->assertSee('Servers')
        ->screenshot(filename: 'dashboard-after-onboarding');
});

it('shows all projects on dashboard', function () {
    $page = loginAndSkipBoarding();

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
    $page = loginAndSkipBoarding();

    $page->assertSee('Servers')
        ->assertSee('localhost')
        ->assertSee('This is a test docker container in development mode')
        ->assertSee('production-web')
        ->assertSee('Production web server cluster')
        ->assertSee('staging-server')
        ->assertSee('Staging environment server')
        ->screenshot();
});
