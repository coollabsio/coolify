<?php

use App\Livewire\Project\Shared\Danger;
use App\Livewire\Project\Shared\Tags;
use App\Livewire\Project\Shared\Webhooks;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0]));

    $this->team = Team::factory()->create();

    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'admin']);

    $this->member = User::factory()->create();
    $this->member->teams()->attach($this->team, ['role' => 'member']);

    $keyId = DB::table('private_keys')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Key',
        'private_key' => 'test-key',
        'team_id' => $this->team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $keyId,
    ]);

    StandaloneDocker::withoutEvents(function () {
        $this->destination = StandaloneDocker::firstOrCreate(
            ['server_id' => $this->server->id, 'network' => 'coolify'],
            ['uuid' => (string) Str::uuid(), 'name' => 'test-docker']
        );
    });

    $this->project = Project::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Project',
        'team_id' => $this->team->id,
    ]);

    $this->environment = $this->project->environments()->first();

    $this->application = Application::factory()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test App',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'status' => 'running',
    ]);

    $this->database = StandalonePostgresql::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test DB',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'testdb',
        'image' => 'postgres:15',
        'status' => 'running',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $this->service = Service::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Service',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'docker_compose_raw' => 'version: "3"',
    ]);
});

// --- Danger Zone: Application ---

test('admin can delete application', function () {
    expect($this->admin->can('delete', $this->application))->toBeTrue();
});

test('member cannot delete application via danger zone', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(Danger::class, ['resource' => $this->application])
        ->call('delete', 'password')
        ->assertDispatched('error');
});

// --- Danger Zone: Database ---

test('admin can delete database', function () {
    expect($this->admin->can('delete', $this->database))->toBeTrue();
});

test('member cannot delete database via danger zone', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(Danger::class, ['resource' => $this->database])
        ->call('delete', 'password')
        ->assertDispatched('error');
});

// --- Danger Zone: Service ---

test('admin can delete service', function () {
    expect($this->admin->can('delete', $this->service))->toBeTrue();
});

test('member cannot delete service via danger zone', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(Danger::class, ['resource' => $this->service])
        ->call('delete', 'password')
        ->assertDispatched('error');
});

// --- Tags: Application ---

test('member cannot add tag to application', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(Tags::class, ['resource' => $this->application])
        ->set('newTags', 'test-tag')
        ->call('submit')
        ->assertDispatched('error');
});

test('admin can add tag to application', function () {
    expect($this->admin->can('update', $this->application))->toBeTrue();
});

// --- Webhooks: Application ---

test('member cannot update application webhooks', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(Webhooks::class, ['resource' => $this->application])
        ->call('submit')
        ->assertDispatched('error');
});

test('admin can update application webhooks', function () {
    expect($this->admin->can('update', $this->application))->toBeTrue();
});

test('member cannot view application webhook secrets', function () {
    $this->application->update([
        'git_repository' => 'coollabsio/coolify',
        'git_branch' => 'main',
        'manual_webhook_secret_github' => 'github-secret-value',
        'manual_webhook_secret_gitlab' => 'gitlab-secret-value',
        'manual_webhook_secret_bitbucket' => 'bitbucket-secret-value',
        'manual_webhook_secret_gitea' => 'gitea-secret-value',
    ]);

    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(Webhooks::class, ['resource' => $this->application->fresh()])
        ->assertSet('githubManualWebhookSecret', null)
        ->assertSet('gitlabManualWebhookSecret', null)
        ->assertSet('bitbucketManualWebhookSecret', null)
        ->assertSet('giteaManualWebhookSecret', null)
        ->assertSee('Hidden (only admins can view)')
        ->assertDontSee('github-secret-value')
        ->assertDontSee('gitlab-secret-value')
        ->assertDontSee('bitbucket-secret-value')
        ->assertDontSee('gitea-secret-value');
});

test('admin can view application webhook secrets', function () {
    $this->application->update([
        'git_repository' => 'coollabsio/coolify',
        'git_branch' => 'main',
        'manual_webhook_secret_github' => 'github-secret-value',
        'manual_webhook_secret_gitlab' => 'gitlab-secret-value',
        'manual_webhook_secret_bitbucket' => 'bitbucket-secret-value',
        'manual_webhook_secret_gitea' => 'gitea-secret-value',
    ]);

    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(Webhooks::class, ['resource' => $this->application->fresh()])
        ->assertSet('githubManualWebhookSecret', 'github-secret-value')
        ->assertSet('gitlabManualWebhookSecret', 'gitlab-secret-value')
        ->assertSet('bitbucketManualWebhookSecret', 'bitbucket-secret-value')
        ->assertSet('giteaManualWebhookSecret', 'gitea-secret-value');
});

// --- Resource Limits (policy checks, mount requires full resource data) ---

test('member cannot update application resource limits', function () {
    expect($this->member->can('update', $this->application))->toBeFalse();
});

test('admin can update application resource limits', function () {
    expect($this->admin->can('update', $this->application))->toBeTrue();
});

test('member cannot update database resource limits', function () {
    expect($this->member->can('update', $this->database))->toBeFalse();
});

test('member cannot update service resource limits', function () {
    expect($this->member->can('update', $this->service))->toBeFalse();
});

// --- Environment Variables (Policy checks) ---

test('admin can manage application environment variables', function () {
    expect($this->admin->can('manageEnvironment', $this->application))->toBeTrue();
});

test('member cannot manage application environment variables', function () {
    expect($this->member->can('manageEnvironment', $this->application))->toBeFalse();
});

test('admin can manage database environment variables', function () {
    expect($this->admin->can('manageEnvironment', $this->database))->toBeTrue();
});

test('member cannot manage database environment variables', function () {
    expect($this->member->can('manageEnvironment', $this->database))->toBeFalse();
});

test('admin can manage service environment variables', function () {
    expect($this->admin->can('manageEnvironment', $this->service))->toBeTrue();
});

test('member cannot manage service environment variables', function () {
    expect($this->member->can('manageEnvironment', $this->service))->toBeFalse();
});

// --- Project Policy ---

test('admin can update project', function () {
    expect($this->admin->can('update', $this->project))->toBeTrue();
});

test('member cannot update project', function () {
    expect($this->member->can('update', $this->project))->toBeFalse();
});

test('admin can delete project', function () {
    expect($this->admin->can('delete', $this->project))->toBeTrue();
});

test('member cannot delete project', function () {
    expect($this->member->can('delete', $this->project))->toBeFalse();
});

// --- Environment Policy ---

test('admin can delete environment', function () {
    expect($this->admin->can('delete', $this->environment))->toBeTrue();
});

test('member cannot delete environment', function () {
    expect($this->member->can('delete', $this->environment))->toBeFalse();
});

// --- Team Policy ---

test('admin can update team', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('update', $this->team))->toBeTrue();
});

test('member cannot update team', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('update', $this->team))->toBeFalse();
});
