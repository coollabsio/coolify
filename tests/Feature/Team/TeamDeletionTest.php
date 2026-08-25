<?php

use App\Actions\Team\DeleteTeam;
use App\Livewire\Team\DangerZone;
use App\Models\Application;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->owner = User::factory()->create();

    // The owner's personal team (created by factory)
    $this->personalTeam = $this->owner->teams()->first();
    $this->owner->teams()->updateExistingPivot($this->personalTeam->id, ['role' => 'owner']);

    // A second team to delete
    $this->teamToDelete = Team::create(['name' => 'Deletable Team', 'personal_team' => false]);
    $this->teamToDelete->members()->attach($this->owner->id, ['role' => 'owner']);
});

test('deleting a team switches session to another team without error', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->teamToDelete]);

    Livewire::test(DangerZone::class)
        ->call('delete')
        ->assertRedirect(route('team.index'));

    // Team should be deleted from the database
    expect(Team::find($this->teamToDelete->id))->toBeNull();

    // Session should now have the personal team
    $sessionTeam = session('currentTeam');
    expect($sessionTeam)->not->toBeNull()
        ->and($sessionTeam->id)->toBe($this->personalTeam->id);
});

test('the danger zone resource list can be refreshed', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->teamToDelete]);

    Livewire::test(DangerZone::class)
        ->call('refreshResources')
        ->assertSuccessful();
});

test('unused private keys do not block team deletion', function () {
    $privateKey = PrivateKey::factory()->create([
        'team_id' => $this->teamToDelete->id,
        'description' => 'Created by Coolify',
    ]);

    expect($this->teamToDelete->isEmpty())->toBeTrue();

    app(DeleteTeam::class)->handle($this->teamToDelete, $this->owner);

    expect(Team::find($this->teamToDelete->id))->toBeNull()
        ->and(PrivateKey::find($privateKey->id))->toBeNull();
});

test('system-wide git sources do not block team deletion', function () {
    Team::forceCreate([
        'id' => 0,
        'name' => 'Root Team',
        'personal_team' => false,
    ]);

    $githubApp = GithubApp::forceCreate([
        'name' => 'System-wide GitHub source',
        'team_id' => $this->teamToDelete->id,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'is_public' => false,
        'is_system_wide' => true,
    ]);
    $gitlabApp = GitlabApp::forceCreate([
        'name' => 'System-wide GitLab source',
        'team_id' => $this->teamToDelete->id,
        'api_url' => 'https://gitlab.com/api/v4',
        'html_url' => 'https://gitlab.com',
        'is_public' => false,
        'is_system_wide' => true,
    ]);

    expect($this->teamToDelete->isEmpty())->toBeTrue();

    app(DeleteTeam::class)->handle($this->teamToDelete, $this->owner);

    expect(Team::find($this->teamToDelete->id))->toBeNull()
        ->and($githubApp->refresh()->team_id)->toBe(0)
        ->and($gitlabApp->refresh()->team_id)->toBe(0);
});

test('the danger zone names and links blocking resource types', function () {
    Project::factory()->count(2)->create(['team_id' => $this->teamToDelete->id]);

    $this->actingAs($this->owner);
    session(['currentTeam' => $this->teamToDelete]);

    Livewire::test(DangerZone::class)
        ->assertSee('This team still owns:')
        ->assertSee('2 projects')
        ->assertSeeHtml('href="'.route('project.index').'"');
});

test('a team with a running application cannot be deleted', function () {
    $server = Server::factory()->create(['team_id' => $this->teamToDelete->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->teamToDelete->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'status' => 'running:healthy',
    ]);

    $this->actingAs($this->owner);
    session(['currentTeam' => $this->teamToDelete]);

    Livewire::test(DangerZone::class)
        ->call('delete')
        ->assertDispatched('error');

    expect(Team::find($this->teamToDelete->id))->not->toBeNull();
});

test('a team with a server cannot be deleted', function () {
    Server::factory()->create(['team_id' => $this->teamToDelete->id]);

    $this->actingAs($this->owner);
    session(['currentTeam' => $this->teamToDelete]);

    Livewire::test(DangerZone::class)
        ->call('delete')
        ->assertDispatched('error', fn (string $event, array $params): bool => $params[0] === 'Delete all team servers before deleting this team.');

    expect(Team::find($this->teamToDelete->id))->not->toBeNull();
});

test('a team with a project but no servers cannot be deleted', function () {
    Project::factory()->create(['team_id' => $this->teamToDelete->id]);

    $member = User::factory()->create();
    $this->teamToDelete->members()->attach($member->id, ['role' => 'member']);
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->teamToDelete->id]);

    $this->actingAs($this->owner);
    session(['currentTeam' => $this->teamToDelete]);

    Livewire::test(DangerZone::class)
        ->call('delete')
        ->assertDispatched('error', fn (string $event, array $params): bool => $params[0] === 'Delete all team resources before deleting this team.');

    expect(Team::find($this->teamToDelete->id))->not->toBeNull()
        ->and(PrivateKey::find($privateKey->id))->not->toBeNull()
        ->and($this->teamToDelete->members()->whereKey($member->id)->exists())->toBeTrue();
});

test('an admin cannot delete a team through the deletion action', function () {
    $admin = User::factory()->create();
    $this->teamToDelete->members()->attach($admin->id, ['role' => 'admin']);

    expect(fn () => app(DeleteTeam::class)->handle($this->teamToDelete, $admin))
        ->toThrow(AuthorizationException::class);

    expect(Team::find($this->teamToDelete->id))->not->toBeNull();
});

test('a stale owner relationship cannot authorize team deletion', function () {
    $this->owner->teams;
    $this->owner->teams()->updateExistingPivot($this->teamToDelete->id, ['role' => 'admin']);

    expect(fn () => app(DeleteTeam::class)->handle($this->teamToDelete, $this->owner))
        ->toThrow(AuthorizationException::class);

    expect(Team::find($this->teamToDelete->id))->not->toBeNull();
});

test('refreshSession clears session when no team exists', function () {
    $user = User::factory()->create();
    // Detach all teams so user has none
    $user->teams()->detach();
    $this->actingAs($user);
    session(['currentTeam' => null]);

    // Should not throw when no team can be resolved
    refreshSession(null);

    expect(session('currentTeam'))->toBeNull();
});

test('deleting a team deletes github and gitlab sources with the same primary key', function () {
    $githubApp = GithubApp::forceCreate([
        'id' => 42,
        'name' => 'GitHub source',
        'team_id' => $this->teamToDelete->id,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'is_public' => false,
    ]);
    $gitlabApp = GitlabApp::forceCreate([
        'id' => 42,
        'name' => 'GitLab source',
        'team_id' => $this->teamToDelete->id,
        'api_url' => 'https://gitlab.com/api/v4',
        'html_url' => 'https://gitlab.com',
        'is_public' => false,
    ]);

    $this->teamToDelete->delete();

    expect(GithubApp::find($githubApp->id))->toBeNull()
        ->and(GitlabApp::find($gitlabApp->id))->toBeNull();
});

test('team deletion rolls back all database changes when an operation fails', function () {
    $member = User::factory()->create();
    $this->teamToDelete->members()->attach($member->id, ['role' => 'member']);

    Team::deleting(function (Team $deletingTeam): void {
        if ($deletingTeam->id === $this->teamToDelete->id) {
            throw new RuntimeException('Simulated deletion failure.');
        }
    });

    expect(fn () => app(DeleteTeam::class)->handle($this->teamToDelete, $this->owner))
        ->toThrow(RuntimeException::class, 'Simulated deletion failure.');

    expect(Team::find($this->teamToDelete->id))->not->toBeNull()
        ->and($this->teamToDelete->members()->whereKey($member->id)->exists())->toBeTrue();
});
