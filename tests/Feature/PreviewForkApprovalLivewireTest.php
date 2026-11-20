<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Livewire\Project\Application\Previews;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const FORK_APPROVAL_PENDING_COMMIT = 'abcdef1234567890abcdef1234567890abcdef12';

function forkApprovalCreateApplication(Team $team): Application
{
    $key = PrivateKey::withoutEvents(fn () => PrivateKey::forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Key',
        'private_key' => 'test-key',
        'team_id' => $team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]));
    $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $key->id]);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $destination = StandaloneDocker::withoutEvents(fn () => StandaloneDocker::firstOrCreate(
        ['server_id' => $server->id, 'network' => 'coolify'],
        ['uuid' => (string) Str::uuid(), 'name' => 'test-docker']
    ));
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => null,
        'build_pack' => 'nixpacks',
    ]);
    $application->settings()->update([
        'is_preview_deployments_enabled' => true,
        'is_pr_deployments_public_enabled' => true,
    ]);

    return $application->fresh();
}

function forkApprovalCreatePendingPreview(Application $application): ApplicationPreview
{
    $preview = ApplicationPreview::create([
        'application_id' => $application->id,
        'pull_request_id' => 7,
        'pull_request_html_url' => 'https://github.com/acme/app/pull/7',
    ]);
    $preview->forceFill([
        'is_fork_pull_request' => true,
        'pending_approval_commit' => FORK_APPROVAL_PENDING_COMMIT,
    ])->save();

    return $preview->fresh();
}

function forkApprovalActAs(Team $team, string $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => $role]);
    test()->actingAs($user);
    session(['currentTeam' => $team]);

    return $user;
}

beforeEach(function () {
    $this->withoutVite();
    config(['app.maintenance.driver' => 'file']);
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0, 'is_dns_validation_enabled' => false]));
    Queue::fake([ApplicationDeploymentJob::class]);

    $this->team = Team::factory()->create();
    $this->application = forkApprovalCreateApplication($this->team);
    $this->preview = forkApprovalCreatePendingPreview($this->application);
});

function forkApprovalExpectUnchanged(ApplicationPreview $preview): void
{
    $preview->refresh();
    expect($preview->pending_approval_commit)->toBe(FORK_APPROVAL_PENDING_COMMIT)
        ->and($preview->approved_commit)->toBeNull()
        ->and(ApplicationDeploymentQueue::query()->count())->toBe(0);
}

it('shows the waiting commit and lets an admin approve and deploy it', function () {
    forkApprovalActAs($this->team, 'admin');

    Livewire::test(Previews::class, ['application' => $this->application])
        ->assertSee('Waiting for approval')
        ->assertSee('abcdef1')
        ->assertSee('Approve and deploy')
        ->call('approveForkCommit', $this->preview->id, FORK_APPROVAL_PENDING_COMMIT)
        ->assertHasNoErrors()
        ->assertRedirect();

    $this->preview->refresh();
    expect($this->preview->approved_commit)->toBe(FORK_APPROVAL_PENDING_COMMIT)
        ->and($this->preview->pending_approval_commit)->toBeNull()
        ->and(ApplicationDeploymentQueue::query()
            ->where('application_id', $this->application->id)
            ->where('pull_request_id', 7)
            ->where('commit', FORK_APPROVAL_PENDING_COMMIT)
            ->exists())->toBeTrue();
});

it('forbids a member to approve and hides the button', function () {
    forkApprovalActAs($this->team, 'member');

    Livewire::test(Previews::class, ['application' => $this->application])
        ->assertSee('Waiting for approval')
        ->assertDontSee('Approve and deploy')
        ->call('approveForkCommit', $this->preview->id, FORK_APPROVAL_PENDING_COMMIT)
        ->assertForbidden();

    forkApprovalExpectUnchanged($this->preview);
});

it('decides by the team of the application after the session switches to another team', function () {
    $user = forkApprovalActAs($this->team, 'member');
    $ownTeam = Team::factory()->create();
    $ownTeam->members()->attach($user->id, ['role' => 'owner']);

    $component = Livewire::test(Previews::class, ['application' => $this->application]);
    session(['currentTeam' => $ownTeam]);

    $component->call('approveForkCommit', $this->preview->id, FORK_APPROVAL_PENDING_COMMIT)
        ->assertForbidden();

    forkApprovalExpectUnchanged($this->preview);
});

it('cannot approve a preview of another application', function () {
    $otherTeam = Team::factory()->create();
    $otherPreview = forkApprovalCreatePendingPreview(forkApprovalCreateApplication($otherTeam));
    forkApprovalActAs($this->team, 'admin');

    $component = Livewire::test(Previews::class, ['application' => $this->application]);

    expect(fn () => $component->call('approveForkCommit', $otherPreview->id, FORK_APPROVAL_PENDING_COMMIT))
        ->toThrow(ModelNotFoundException::class);

    forkApprovalExpectUnchanged($otherPreview);
});

it('shows an error and changes nothing when the commit is stale', function () {
    forkApprovalActAs($this->team, 'admin');

    Livewire::test(Previews::class, ['application' => $this->application])
        ->call('approveForkCommit', $this->preview->id, '1111111111111111111111111111111111111111')
        ->assertDispatched('error', 'The pull request changed. Review the new commit and approve it again.')
        ->assertNoRedirect();

    forkApprovalExpectUnchanged($this->preview);
});

it('saves the fork approval setting for an admin', function () {
    forkApprovalActAs($this->team, 'admin');

    Livewire::test(Previews::class, ['application' => $this->application])
        ->assertSee('Require approval for fork pull requests')
        ->assertSet('isPrDeploymentsPublicApprovalRequired', true)
        ->set('isPrDeploymentsPublicApprovalRequired', false)
        ->call('savePreviewSettings')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    expect($this->application->fresh()->settings->is_pr_deployments_public_approval_required)->toBeFalse();
});

it('hides the fork approval setting when public PR deployments are off', function () {
    $this->application->settings()->update(['is_pr_deployments_public_enabled' => false]);
    forkApprovalActAs($this->team, 'admin');

    Livewire::test(Previews::class, ['application' => $this->application->fresh()])
        ->assertDontSee('Require approval for fork pull requests');
});
