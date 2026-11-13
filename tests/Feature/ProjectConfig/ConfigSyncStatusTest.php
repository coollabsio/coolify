<?php

use App\Enums\ConfigRevisionStatus;
use App\Livewire\Project\Show;
use App\Models\Environment;
use App\Models\EnvironmentConfigLink;
use App\Models\EnvironmentConfigRevision;
use App\Services\ProjectConfig\ConfigSyncStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->fx = projectConfigFixture();
    $this->link = EnvironmentConfigLink::factory()->create(['environment_id' => $this->fx['environment']->id, 'mode' => 'git', 'branch' => 'main']);
});

function syncStatusRevision(object $test, ConfigRevisionStatus $status, array $extra = []): EnvironmentConfigRevision
{
    return EnvironmentConfigRevision::factory()->create($extra + ['link_id' => $test->link->id, 'status' => $status, 'commit_sha' => str_repeat('a', 40)]);
}

it('has no status for an environment without a link', function () {
    $this->link->delete();

    expect(ConfigSyncStatus::forEnvironment($this->fx['environment']->fresh()))->toBeNull();
});

it('reports each state of the latest revision', function (ConfigRevisionStatus $status, string $state, string $tone, string $label, bool $attention) {
    syncStatusRevision($this, ConfigRevisionStatus::Applied, ['commit_sha' => str_repeat('b', 40)]);
    syncStatusRevision($this, $status, ['plan' => ['changes' => [['kind' => 'remove'], ['kind' => 'update'], ['kind' => 'create']]]]);

    $summary = ConfigSyncStatus::forLink($this->link->fresh());

    expect($summary['state'])->toBe($state)
        ->and($summary['tone'])->toBe($tone)
        ->and($summary['label'])->toBe($label)
        ->and($summary['needs_attention'])->toBe($attention);
})->with([
    'applied' => [ConfigRevisionStatus::Applied, 'synced', 'success', 'Synced · aaaaaaa', false],
    'applying' => [ConfigRevisionStatus::Applying, 'applying', 'progress', 'Applying aaaaaaa', false],
    'planned' => [ConfigRevisionStatus::Planned, 'applying', 'progress', 'Applying aaaaaaa', false],
    'waiting' => [ConfigRevisionStatus::WaitingApproval, 'waiting', 'warning', '3 changes need approval', true],
    'failed' => [ConfigRevisionStatus::Failed, 'failed', 'error', 'Sync failed', true],
]);

it('ignores skipped and superseded revisions', function () {
    syncStatusRevision($this, ConfigRevisionStatus::Applied);
    syncStatusRevision($this, ConfigRevisionStatus::Superseded);
    syncStatusRevision($this, ConfigRevisionStatus::Skipped);

    expect(ConfigSyncStatus::forLink($this->link->fresh())['state'])->toBe('synced');
});

it('reports a paused link before any revision state', function () {
    syncStatusRevision($this, ConfigRevisionStatus::Failed);
    $this->link->update(['suspended_at' => now()]);

    expect(ConfigSyncStatus::forLink($this->link->fresh()))->toMatchArray(['state' => 'paused', 'tone' => 'neutral', 'label' => 'Sync paused', 'needs_attention' => false]);
});

it('waits for the first file when nothing was planned yet', function () {
    expect(ConfigSyncStatus::forLink($this->link->fresh()))->toMatchArray(['state' => 'waiting_first', 'tone' => 'neutral', 'label' => 'Waiting for the first push']);
});

it('explains what needs attention in one sentence', function () {
    syncStatusRevision($this, ConfigRevisionStatus::WaitingApproval, ['commit_sha' => str_repeat('c', 40), 'plan' => ['changes' => [
        ['kind' => 'remove', 'key' => 'worker'], ['kind' => 'update', 'key' => 'api'], ['kind' => 'update', 'key' => 'api'],
    ]]]);

    expect(ConfigSyncStatus::forLink($this->link->fresh())['message'])->toBe('Commit ccccccc removes worker and changes 2 settings. Deploys from this push wait.');

    syncStatusRevision($this, ConfigRevisionStatus::Failed, ['error' => 'resources.api.server: No server is named "x" in this team.']);
    expect(ConfigSyncStatus::forLink($this->link->fresh())['message'])->toBe('The last sync failed: resources.api.server: No server is named "x" in this team.');
});

it('shows the sync state of linked environments on the project page', function () {
    syncStatusRevision($this, ConfigRevisionStatus::WaitingApproval, ['plan' => ['changes' => [['kind' => 'remove']]]]);
    Environment::create(['project_id' => $this->fx['project']->id, 'name' => 'staging']);
    $this->actingAs($this->fx['user']);

    Livewire::test(Show::class, ['project_uuid' => $this->fx['project']->uuid])
        ->assertViewHas('environmentsJs', function ($environments) {
            $config = collect($environments)->pluck('config', 'name');

            return $config['production']['label'] === 'Needs approval'
                && $config['production']['title'] === 'coolify.json: 1 change needs approval'
                && $config['production']['dotClass'] === 'bg-warning'
                && $config['staging'] === null;
        });
});
