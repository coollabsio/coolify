<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create root team and admin user (instance admin)
    $this->rootTeam = Team::factory()->create(['id' => 0, 'name' => 'Root Team']);
    $this->adminUser = User::factory()->create();
    $this->rootTeam->members()->attach($this->adminUser->id, ['role' => 'owner']);
    $this->actingAs($this->adminUser);
    session(['currentTeam' => $this->rootTeam]);
});

it('deletes a user whose team has a github app with applications', function () {
    // Create the user to be deleted with their own team
    $targetUser = User::factory()->create();
    $targetTeam = $targetUser->teams()->first(); // created by User::created event

    // Create a private key for the team
    $privateKey = PrivateKey::factory()->create(['team_id' => $targetTeam->id]);

    // Create a server and destination for the team
    $server = Server::factory()->create([
        'team_id' => $targetTeam->id,
        'private_key_id' => $privateKey->id,
    ]);
    $destination = StandaloneDocker::factory()->create(['server_id' => $server->id]);

    // Create a project and environment
    $project = Project::factory()->create(['team_id' => $targetTeam->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    // Create a GitHub App owned by the target team
    $githubApp = GithubApp::create([
        'name' => 'Test GitHub App',
        'team_id' => $targetTeam->id,
        'private_key_id' => $privateKey->id,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'is_public' => false,
    ]);

    // Create an application that uses the GitHub App as its source
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'source_id' => $githubApp->id,
        'source_type' => GithubApp::class,
    ]);

    // Delete the user — this should NOT throw a GithubApp exception
    $targetUser->delete();

    // Assert user is deleted
    expect(User::find($targetUser->id))->toBeNull();

    // Assert the GitHub App is deleted
    expect(GithubApp::find($githubApp->id))->toBeNull();

    // Assert the application is deleted
    expect(Application::find($application->id))->toBeNull();
});

it('does not delete system-wide github apps when deleting a different team', function () {
    // Create a system-wide GitHub App owned by the root team
    $rootPrivateKey = PrivateKey::factory()->create(['team_id' => $this->rootTeam->id]);
    $systemGithubApp = GithubApp::create([
        'name' => 'System GitHub App',
        'team_id' => $this->rootTeam->id,
        'private_key_id' => $rootPrivateKey->id,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'is_public' => false,
        'is_system_wide' => true,
    ]);

    // Create a target user with their own team
    $targetUser = User::factory()->create();
    $targetTeam = $targetUser->teams()->first();

    // Create an application on the target team that uses the system-wide GitHub App
    $privateKey = PrivateKey::factory()->create(['team_id' => $targetTeam->id]);
    $server = Server::factory()->create([
        'team_id' => $targetTeam->id,
        'private_key_id' => $privateKey->id,
    ]);
    $destination = StandaloneDocker::factory()->create(['server_id' => $server->id]);
    $project = Project::factory()->create(['team_id' => $targetTeam->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'source_id' => $systemGithubApp->id,
        'source_type' => GithubApp::class,
    ]);

    // Delete the target user — should NOT throw or delete the system-wide GitHub App
    $targetUser->delete();

    // Assert user is deleted
    expect(User::find($targetUser->id))->toBeNull();

    // Assert the system-wide GitHub App still exists
    expect(GithubApp::find($systemGithubApp->id))->not->toBeNull();
});

it('transfers instance-wide github app to root team when owning user is deleted', function () {
    // Create a user whose team owns an instance-wide GitHub App
    $targetUser = User::factory()->create();
    $targetTeam = $targetUser->teams()->first();

    $targetPrivateKey = PrivateKey::factory()->create(['team_id' => $targetTeam->id]);
    $instanceWideApp = GithubApp::create([
        'name' => 'Instance-Wide GitHub App',
        'team_id' => $targetTeam->id,
        'private_key_id' => $targetPrivateKey->id,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'is_public' => false,
        'is_system_wide' => true,
    ]);

    // Create an application on the ROOT team that uses this instance-wide GitHub App
    $rootPrivateKey = PrivateKey::factory()->create(['team_id' => $this->rootTeam->id]);
    $rootServer = Server::factory()->create([
        'team_id' => $this->rootTeam->id,
        'private_key_id' => $rootPrivateKey->id,
    ]);
    $rootDestination = StandaloneDocker::factory()->create(['server_id' => $rootServer->id]);
    $rootProject = Project::factory()->create(['team_id' => $this->rootTeam->id]);
    $rootEnvironment = Environment::factory()->create(['project_id' => $rootProject->id]);

    $otherTeamApp = Application::factory()->create([
        'environment_id' => $rootEnvironment->id,
        'destination_id' => $rootDestination->id,
        'destination_type' => StandaloneDocker::class,
        'source_id' => $instanceWideApp->id,
        'source_type' => GithubApp::class,
    ]);

    // Delete the user — should succeed and transfer the instance-wide app to root team
    $targetUser->delete();

    // Assert user is deleted
    expect(User::find($targetUser->id))->toBeNull();

    // Assert the instance-wide GitHub App is preserved and transferred to root team
    $instanceWideApp->refresh();
    expect($instanceWideApp)->not->toBeNull();
    expect($instanceWideApp->team_id)->toBe($this->rootTeam->id);

    // Assert the other team's application still has its source intact
    $otherTeamApp->refresh();
    expect($otherTeamApp->source_id)->toBe($instanceWideApp->id);
    expect($otherTeamApp->source_type)->toBe(GithubApp::class);
});

it('transfers instance-wide github app to root team when team is deleted directly', function () {
    // Create a team that owns an instance-wide GitHub App
    $targetUser = User::factory()->create();
    $targetTeam = $targetUser->teams()->first();

    $targetPrivateKey = PrivateKey::factory()->create(['team_id' => $targetTeam->id]);
    $instanceWideApp = GithubApp::create([
        'name' => 'Instance-Wide GitHub App',
        'team_id' => $targetTeam->id,
        'private_key_id' => $targetPrivateKey->id,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'is_public' => false,
        'is_system_wide' => true,
    ]);

    // Create an application on the ROOT team that uses this instance-wide GitHub App
    $rootPrivateKey = PrivateKey::factory()->create(['team_id' => $this->rootTeam->id]);
    $rootServer = Server::factory()->create([
        'team_id' => $this->rootTeam->id,
        'private_key_id' => $rootPrivateKey->id,
    ]);
    $rootDestination = StandaloneDocker::factory()->create(['server_id' => $rootServer->id]);
    $rootProject = Project::factory()->create(['team_id' => $this->rootTeam->id]);
    $rootEnvironment = Environment::factory()->create(['project_id' => $rootProject->id]);

    $otherTeamApp = Application::factory()->create([
        'environment_id' => $rootEnvironment->id,
        'destination_id' => $rootDestination->id,
        'destination_type' => StandaloneDocker::class,
        'source_id' => $instanceWideApp->id,
        'source_type' => GithubApp::class,
    ]);

    // Delete the team directly — should transfer instance-wide app to root team
    $targetTeam->delete();

    // Assert the instance-wide GitHub App is preserved and transferred to root team
    $instanceWideApp->refresh();
    expect($instanceWideApp)->not->toBeNull();
    expect($instanceWideApp->team_id)->toBe($this->rootTeam->id);

    // Assert the other team's application still has its source intact
    $otherTeamApp->refresh();
    expect($otherTeamApp->source_id)->toBe($instanceWideApp->id);
    expect($otherTeamApp->source_type)->toBe(GithubApp::class);
});
