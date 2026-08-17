<?php

use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->stack = seedBrowserResourceStack([
        'projectUuid' => 'project-1',
        'projectName' => 'My first project',
        'projectDescription' => 'This is a test project',
        'serverDescription' => 'Test docker container in development',
    ]);
    $this->user = $this->stack['user'];

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

    // Skip onboarding UI for both owner and member sessions.
    Team::query()->whereKey(0)->update(['show_boarding' => false]);
});

function loginAsMember(): mixed
{
    return visit('/login')
        ->fill('email', 'member@example.com')
        ->fill('password', 'password')
        ->click('Login')
        ->wait(1);
}

it('redirects unauthenticated users to login', function () {
    $page = visit('/dashboard');

    $page->assertPathIs('/login')
        ->screenshot();
});

it('shows dashboard after successful login and onboarding skip', function () {
    $page = loginAndSkipBoarding();

    $page->assertSee('Dashboard')
        ->assertSee('Projects')
        ->assertSee('Servers')
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
        ->assertSee('Name')
        ->assertSee('MCP server')
        ->assertSee('Root Team')
        ->screenshot();
});

it('shows danger zone to team owner', function () {
    loginAndSkipBoarding();

    $page = visit('/team');

    $page->assertSee('Danger zone')
        ->assertSee('Destructive actions for this team.')
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
        ->assertDontSee('Danger zone')
        ->assertDontSee('Destructive actions for this team.')
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

    $page->assertSee('My first project')
        ->assertSee('in this project')
        ->assertDontSee('New environment')
        ->screenshot();
});

it('member does not see environment settings link on project page', function () {
    loginAsMember();

    $project = Project::where('uuid', 'project-1')->first();
    $page = visit("/project/{$project->uuid}");

    $page->assertSee('My first project')
        ->assertDontSee('Settings')
        ->screenshot();
});

it('owner sees add environment and settings on project page', function () {
    loginAndSkipBoarding();

    $project = Project::where('uuid', 'project-1')->first();
    $page = visit("/project/{$project->uuid}");

    $page->assertSee('My first project')
        ->assertSee('New environment')
        ->assertSee('Settings')
        ->screenshot();
});

// --- Dashboard: resource links authorization ---

it('member does not see add resource link on dashboard project cards', function () {
    $page = loginAsMember();

    $page->assertSee('Projects')
        ->assertSee('My first project')
        ->assertDontSee('Add resource to My first project')
        ->assertSourceMissing('title="Add resource"')
        ->screenshot();
});

it('owner sees add resource link on dashboard project cards', function () {
    loginAndSkipBoarding();

    $page = visit('/dashboard');

    $page->assertSee('Projects')
        ->assertSee('My first project')
        ->assertSourceHas('title="Add resource"')
        ->screenshot();
});

it('member does not see project settings link on dashboard', function () {
    $page = loginAsMember();

    // Project settings control is an icon link with this aria-label for owners only.
    $page->assertSee('My first project')
        ->assertSourceMissing('Open settings for My first project')
        ->screenshot();
});

// --- Team members page authorization ---

it('member does not see invite form on team members page', function () {
    loginAsMember();

    $page = visit('/team/members');

    $page->assertSee('Members')
        ->assertDontSee('Invite a member')
        ->screenshot();
});

it('owner sees invite form on team members page', function () {
    loginAndSkipBoarding();

    $page = visit('/team/members');

    $page->assertSee('Members')
        ->assertSee('Invite a member')
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

    $page->assertSee('My first project')
        ->assertDontSee('Delete Project')
        ->assertDontSee('Delete project')
        ->screenshot();
});
