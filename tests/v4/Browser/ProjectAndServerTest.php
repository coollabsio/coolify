<?php

use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->stack = seedBrowserResourceStack([
        'projectUuid' => 'project-core-1',
        'projectName' => 'Core Project Alpha',
        'projectDescription' => 'Primary browser project',
        'serverName' => 'localhost',
        'serverDescription' => 'Local testing host',
    ]);

    Project::create([
        'uuid' => 'project-core-2',
        'name' => 'Core Project Beta',
        'description' => 'Secondary browser project',
        'team_id' => 0,
    ]);

    Server::create([
        'uuid' => 'server-core-2',
        'name' => 'remote-web',
        'description' => 'Remote web server',
        'ip' => '10.0.0.50',
        'team_id' => 0,
        'private_key_id' => 1,
        'proxy' => [
            'type' => ProxyTypes::TRAEFIK->value,
            'status' => ProxyStatus::EXITED->value,
        ],
    ]);

    $this->application = createBrowserApplication($this->stack, [
        'uuid' => 'app-core-env',
        'name' => 'Env Listed App',
    ]);
});

it('shows dashboard with projects and servers after login', function () {
    $page = loginAndSkipBoarding();

    $page->assertSee('Dashboard')
        ->assertSee('Core Project Alpha')
        ->assertSee('Core Project Beta')
        ->assertSee('localhost')
        ->assertSee('remote-web')
        ->screenshot(filename: 'core-dashboard');
});

it('navigates to project environments page', function () {
    loginAndSkipBoarding();

    $project = $this->stack['project'];
    $page = visit("/project/{$project->uuid}");

    $page->assertSee('Core Project Alpha')
        ->assertSee('environment')
        ->screenshot(filename: 'core-project-environments');
});

it('navigates to environment resources listing applications', function () {
    loginAndSkipBoarding();

    $project = $this->stack['project'];
    $environment = $this->stack['environment'];

    $page = visit("/project/{$project->uuid}/environment/{$environment->uuid}");

    $page->assertSee('Env Listed App')
        ->screenshot(filename: 'core-environment-resources');
});

it('shows server configuration page for owner', function () {
    loginAndSkipBoarding();

    $server = $this->stack['server'];
    $page = visit("/server/{$server->uuid}");

    $page->assertSee('localhost')
        ->assertSee('General')
        ->assertSee('Configuration')
        ->assertSee('Save')
        ->screenshot(filename: 'core-server-configuration');
});

it('shows team general and members pages', function () {
    loginAndSkipBoarding();

    visit('/team')
        ->assertSee('General')
        ->assertSee('Danger Zone')
        ->screenshot(filename: 'core-team-general');

    visit('/team/members')
        ->assertSee('Members')
        ->assertSee('Invite a member')
        ->screenshot(filename: 'core-team-members');
});

it('shows projects index', function () {
    loginAndSkipBoarding();

    visit('/projects')
        ->assertSee('Core Project Alpha')
        ->assertSee('Core Project Beta')
        ->screenshot(filename: 'core-projects-index');
});

it('shows servers index', function () {
    loginAndSkipBoarding();

    visit('/servers')
        ->assertSee('localhost')
        ->assertSee('remote-web')
        ->screenshot(filename: 'core-servers-index');
});

it('protects core routes when unauthenticated', function () {
    visit('/dashboard')->assertPathIs('/login');
    visit('/projects')->assertPathIs('/login');
    visit('/servers')->assertPathIs('/login');
    visit('/team')->assertPathIs('/login');

    $project = $this->stack['project'];
    visit("/project/{$project->uuid}")->assertPathIs('/login');

    $server = $this->stack['server'];
    visit("/server/{$server->uuid}")->assertPathIs('/login')
        ->screenshot(filename: 'core-unauthenticated-redirect');
});
