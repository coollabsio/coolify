<?php

use App\Livewire\Railway\Canvas;
use App\Livewire\Railway\Projects;
use App\Livewire\Railway\ServicePanel;
use App\Livewire\Railway\Settings;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\RailwayResourceMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // `id` is not fillable, so seed record 0 unguarded so instanceSettings() (findOrFail(0)) resolves.
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    Queue::fake();

    $this->user = User::factory()->create(['password' => Hash::make('test-password')]);
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'network' => 'test-network-'.fake()->unique()->word(),
    ]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Mimic Internal']);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->application = Application::factory()->create([
        'name' => 'staging-app',
        'status' => 'running:healthy',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('renders the Railway projects dashboard with the project card', function () {
    Livewire::test(Projects::class)
        ->assertOk()
        ->assertSee('Projects')
        ->assertSee('Mimic Internal');
});

it('renders the architecture canvas with the resource node', function () {
    Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->assertOk()
        ->assertSee('staging-app')
        ->assertSee('Online');
});

it('opens the service panel for a selected resource', function () {
    Livewire::test(ServicePanel::class, [
        'environment' => $this->environment,
        'project' => $this->project,
    ])
        ->assertSet('open', false)
        ->dispatch('openServicePanel', uuid: $this->application->uuid, kind: 'application')
        ->assertSet('open', true)
        ->assertSet('kind', 'application')
        ->assertSee('staging-app')
        ->assertSee('Deployments');
});

it('persists a canvas node position', function () {
    Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])->call('savePosition', $this->application->uuid, 120, 240);

    $this->assertDatabaseHas('railway_canvas_positions', [
        'environment_id' => $this->environment->id,
        'resource_uuid' => $this->application->uuid,
        'x' => 120,
        'y' => 240,
    ]);
});

it('maps an environment\'s resources into node view-models', function () {
    $resources = RailwayResourceMapper::resourcesFor($this->environment);
    expect($resources)->toHaveCount(1);

    $node = RailwayResourceMapper::toNode($resources->first(), $this->project->uuid, $this->environment->uuid);
    expect($node['name'])->toBe('staging-app');
    expect($node['kind'])->toBe('application');
    expect($node['online'])->toBeTrue();
});

it('treats a non-running status as offline', function () {
    $this->application->update(['status' => 'exited']);

    expect(RailwayResourceMapper::isOnline($this->application->fresh()))->toBeFalse();
});

it('invites a new member from the Railway settings page', function () {
    Livewire::test(Settings::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->set('section', 'members')
        ->set('inviteEmail', 'newbie@example.com')
        ->set('inviteRole', 'member')
        ->call('inviteMember', false)
        ->assertHasNoErrors()
        ->assertSet('generatedInviteLink', fn ($link) => filled($link));

    $this->assertDatabaseHas('team_invitations', [
        'team_id' => $this->team->id,
        'email' => 'newbie@example.com',
        'role' => 'member',
    ]);
    // A brand-new invitee also gets a user record with a forced password reset.
    $this->assertDatabaseHas('users', [
        'email' => 'newbie@example.com',
        'force_password_reset' => true,
    ]);
});

it('rejects an invalid invite email', function () {
    Livewire::test(Settings::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->set('section', 'members')
        ->set('inviteEmail', 'not-an-email')
        ->call('inviteMember', false)
        ->assertHasErrors('inviteEmail');

    expect(TeamInvitation::count())->toBe(0);
});

it('prevents a member from inviting an owner (privilege escalation)', function () {
    $member = User::factory()->create(['password' => Hash::make('test-password')]);
    $member->teams()->attach($this->team, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    Livewire::test(Settings::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->set('section', 'members')
        ->set('inviteEmail', 'boss@example.com')
        ->set('inviteRole', 'owner')
        ->call('inviteMember', false);

    expect(TeamInvitation::whereEmail('boss@example.com')->exists())->toBeFalse();
});

it('revokes a pending invitation', function () {
    $invitation = TeamInvitation::create([
        'team_id' => $this->team->id,
        'uuid' => new_public_id(32),
        'email' => 'pending@example.com',
        'role' => 'member',
        'link' => 'https://example.com/invite',
        'via' => 'link',
    ]);

    Livewire::test(Settings::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->set('section', 'members')
        ->call('revokeInvitation', $invitation->id);

    $this->assertDatabaseMissing('team_invitations', ['id' => $invitation->id]);
});

it('changes a member role and removes a member', function () {
    $member = User::factory()->create(['password' => Hash::make('test-password')]);
    $member->teams()->attach($this->team, ['role' => 'member']);

    $component = Livewire::test(Settings::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])->set('section', 'members');

    $component->call('changeRole', $member->id, 'admin');
    $this->assertDatabaseHas('team_user', [
        'team_id' => $this->team->id,
        'user_id' => $member->id,
        'role' => 'admin',
    ]);

    $component->call('removeMember', $member->id);
    $this->assertDatabaseMissing('team_user', [
        'team_id' => $this->team->id,
        'user_id' => $member->id,
    ]);
});
