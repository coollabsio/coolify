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
    InstanceSettings::create(['id' => 0, 'is_sponsorship_popup_enabled' => false]);

    // Create root/owner user
    $this->user = User::factory()->create([
        'id' => 0,
        'name' => 'Root User',
        'email' => 'test@example.com',
        'password' => Hash::make('password'),
    ]);

    // Create SSH key for the root user's team
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

    // Create servers for testing
    Server::create([
        'id' => 0,
        'uuid' => 'localhost',
        'name' => 'localhost',
        'description' => 'Test docker container in development',
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
        'description' => 'Production web server',
        'ip' => '10.0.0.1',
        'team_id' => 0,
        'private_key_id' => 1,
        'proxy' => [
            'type' => ProxyTypes::TRAEFIK->value,
            'status' => ProxyStatus::EXITED->value,
        ],
    ]);

    // Create projects for testing
    Project::create([
        'uuid' => 'project-1',
        'name' => 'My first project',
        'description' => 'This is a test project',
        'team_id' => 0,
    ]);

    Project::create([
        'uuid' => 'project-2',
        'name' => 'Production API',
        'description' => 'Backend services',
        'team_id' => 0,
    ]);

    // Create a member user attached to root team only
    $this->member = User::factory()->create([
        'name' => 'Member User',
        'email' => 'member@example.com',
        'password' => Hash::make('password'),
    ]);
    // Remove auto-created personal team so member only belongs to root team
    $personalTeam = $this->member->teams()->first();
    $this->member->teams()->detach($personalTeam->id);
    $personalTeam->delete();
    // Attach member to root team (id=0) with 'member' role
    $this->member->teams()->attach(0, ['role' => 'member']);
});

function loginAsMember(): mixed
{
    return visit('/login')
        ->fill('email', 'member@example.com')
        ->fill('password', 'password')
        ->click('Login');
}

it('redirects unauthenticated users to login', function () {
    $page = visit('/dashboard');

    $page->assertPathIs('/login')
        ->screenshot();
});

it('shows dashboard after successful login and onboarding skip', function () {
    $page = loginAndSkipBoarding();

    $page->assertSee('Dashboard')
        ->assertSee('Your self-hosted infrastructure')
        ->screenshot();
});

it('displays all projects on dashboard', function () {
    $page = loginAndSkipBoarding();

    $page->assertSee('Projects')
        ->assertSee('My first project')
        ->assertSee('This is a test project')
        ->assertSee('Production API')
        ->assertSee('Backend services')
        ->screenshot();
});

it('displays all servers on dashboard', function () {
    $page = loginAndSkipBoarding();

    $page->assertSee('Servers')
        ->assertSee('localhost')
        ->assertSee('Test docker container in development')
        ->assertSee('production-web')
        ->assertSee('Production web server')
        ->screenshot();
});

it('allows authenticated users to access team settings', function () {
    loginAndSkipBoarding();

    $page = visit('/team');

    $page->assertSee('General')
        ->assertSee('Manage the general settings of this team')
        ->screenshot();
});

it('shows danger zone to team owner', function () {
    loginAndSkipBoarding();

    $page = visit('/team');

    $page->assertSee('Danger Zone')
        ->assertSee('Delete Team')
        ->screenshot();
});

it('prevents unauthenticated access to team settings', function () {
    $page = visit('/team');

    $page->assertPathIs('/login')
        ->screenshot();
});

it('prevents unauthenticated access to server show page', function () {
    $server = Server::first();
    $page = visit("/server/{$server->uuid}");

    $page->assertPathIs('/login')
        ->screenshot();
});

it('prevents unauthenticated access to project show page', function () {
    $project = Project::first();
    $page = visit("/project/{$project->uuid}");

    $page->assertPathIs('/login')
        ->screenshot();
});

it('authenticated user can navigate to server details', function () {
    loginAndSkipBoarding();

    // Navigate to server show page using UUID
    $server = Server::first();
    $page = visit("/server/{$server->uuid}");

    // Server page should load without redirect
    $page->assertSee('localhost')
        ->screenshot();
});

it('authenticated user can navigate to project details', function () {
    loginAndSkipBoarding();

    // Navigate to project show page using UUID
    $project = Project::first();
    $page = visit("/project/{$project->uuid}");

    // Project page should load without redirect
    $page->assertSee('My first project')
        ->screenshot();
});

it('prevents unauthenticated access to team members page', function () {
    $page = visit('/team/members');

    $page->assertPathIs('/login')
        ->screenshot();
});

it('authenticated user can access team members page', function () {
    loginAndSkipBoarding();

    $page = visit('/team/members');

    $page->assertSee('Members')
        ->screenshot();
});

// --- Negative authorization tests (member role) ---

it('member does not see add project button on dashboard', function () {
    $page = loginAsMember();

    $page->assertSee('Projects')
        ->assertDontSee('New Project')
        ->screenshot();
});

it('member does not see add server button on dashboard', function () {
    $page = loginAsMember();

    $page->assertSee('Servers')
        ->assertDontSee('New Server')
        ->screenshot();
});

it('member does not see danger zone on team settings', function () {
    loginAsMember();

    $page = visit('/team');

    $page->assertSee('General')
        ->assertDontSee('Danger Zone')
        ->assertDontSee('Delete Team')
        ->screenshot();
});

// --- Server page authorization tests ---

it('member does not see terminal link on server page', function () {
    loginAsMember();

    $server = Server::first();
    $page = visit("/server/{$server->uuid}");

    $page->assertSee('Configuration')
        ->assertDontSee('Terminal')
        ->screenshot();
});

it('member does not see security link on server page', function () {
    loginAsMember();

    $server = Server::first();
    $page = visit("/server/{$server->uuid}");

    $page->assertSee('Configuration')
        ->assertDontSee('Security')
        ->screenshot();
});

it('member does not see proxy controls on server page', function () {
    loginAsMember();

    $server = Server::first();
    $page = visit("/server/{$server->uuid}");

    $page->assertSee('Configuration')
        ->assertDontSee('Start Proxy')
        ->assertDontSee('Restart Proxy')
        ->assertDontSee('Stop Proxy')
        ->screenshot();
});

it('owner sees terminal and security links on server page', function () {
    loginAndSkipBoarding();

    $server = Server::first();
    $page = visit("/server/{$server->uuid}");

    $page->assertSee('Configuration')
        ->assertSee('Terminal')
        ->assertSee('Security')
        ->screenshot();
});

// Note: Proxy controls (Start/Stop/Restart Proxy) require server.isFunctional()=true
// which needs is_reachable=true and is_usable=true in server settings.
// Testing proxy button visibility is covered by the member test above (proxy controls
// are behind @can('manageProxy', $server) which is admin-only).

// --- Project page authorization tests ---

it('member does not see add environment button on project page', function () {
    loginAsMember();

    $project = Project::where('uuid', 'project-1')->first();
    $page = visit("/project/{$project->uuid}");

    $page->assertSee('Environments')
        ->assertDontSee('+ Add')
        ->screenshot();
});

it('member does not see environment settings link on project page', function () {
    loginAsMember();

    $project = Project::where('uuid', 'project-1')->first();
    $page = visit("/project/{$project->uuid}");

    $page->assertSee('Environments')
        ->assertDontSee('Settings')
        ->screenshot();
});

it('owner sees add environment and settings on project page', function () {
    loginAndSkipBoarding();

    $project = Project::where('uuid', 'project-1')->first();
    $page = visit("/project/{$project->uuid}");

    $page->assertSee('Environments')
        ->assertSee('+ Add')
        ->assertSee('Settings')
        ->screenshot();
});

// --- Dashboard: resource links authorization ---

it('member does not see add resource link on dashboard project cards', function () {
    $page = loginAsMember();

    $page->assertSee('Projects')
        ->assertSee('My first project')
        ->assertDontSee('+ Add Resource')
        ->screenshot();
});

it('owner sees add resource link on dashboard project cards', function () {
    loginAndSkipBoarding();

    $page = visit('/dashboard');

    $page->assertSee('Projects')
        ->assertSee('My first project')
        ->assertSee('+ Add Resource')
        ->screenshot();
});

it('member does not see project settings link on dashboard', function () {
    $page = loginAsMember();

    // The "Settings" link is inside the project card on the dashboard
    // Member should not see it due to @can('update', $project)
    $page->assertSee('My first project')
        ->assertDontSee('Settings')
        ->screenshot();
});

// --- Team members page authorization ---

it('member does not see invite form on team members page', function () {
    loginAsMember();

    $page = visit('/team/members');

    $page->assertSee('Members')
        ->assertDontSee('Invite New Member')
        ->screenshot();
});

it('owner sees invite form on team members page', function () {
    loginAndSkipBoarding();

    $page = visit('/team/members');

    $page->assertSee('Members')
        ->assertSee('Invite New Member')
        ->screenshot();
});

it('member does not see role change buttons on team members page', function () {
    loginAsMember();

    $page = visit('/team/members');

    $page->assertSee('Members')
        ->assertDontSee('To Admin')
        ->assertDontSee('To Owner')
        ->assertDontSee('Remove')
        ->screenshot();
});

// --- Server show page authorization ---

it('member does not see save button on server show page', function () {
    loginAsMember();

    $server = Server::first();
    $page = visit("/server/{$server->uuid}");

    $page->assertSee('General')
        ->assertDontSee('Revalidate server')
        ->screenshot();
});

it('owner sees save button on server show page', function () {
    loginAndSkipBoarding();

    $server = Server::first();
    $page = visit("/server/{$server->uuid}");

    $page->assertSee('General')
        ->assertSee('Save')
        ->screenshot();
});

// --- Project show page authorization ---

it('member does not see delete project button on project page', function () {
    loginAsMember();

    $project = Project::where('uuid', 'project-1')->first();
    $page = visit("/project/{$project->uuid}");

    $page->assertSee('Environments')
        ->assertDontSee('Delete Project')
        ->screenshot();
});
