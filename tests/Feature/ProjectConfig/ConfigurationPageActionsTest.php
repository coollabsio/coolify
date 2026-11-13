<?php

use App\Enums\ConfigRevisionStatus;
use App\Jobs\PlanEnvironmentConfigJob;
use App\Livewire\Project\EnvironmentConfiguration;
use App\Models\EnvironmentConfigLink;
use App\Models\EnvironmentConfigRevision;
use App\Models\User;
use App\Services\ProjectConfig\RepositoryFile;
use App\Services\ProjectConfig\RepositoryFileReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake([PlanEnvironmentConfigJob::class]);
    $this->fx = projectConfigFixture();
    $this->params = ['project_uuid' => $this->fx['project']->uuid, 'environment_uuid' => $this->fx['environment']->uuid];
    app()->instance(RepositoryFileReader::class, new class extends RepositoryFileReader
    {
        public function read(EnvironmentConfigLink $link, ?string $commit = null): RepositoryFile
        {
            return new RepositoryFile(json_encode(['version' => 1, 'resources' => ['cache' => ['type' => 'redis', 'server' => 'prod-1']],
                'environments' => ['production' => ['branch' => 'main']]]), str_repeat('f', 40), false);
        }
    });
});

function pageMember(object $test): User
{
    $member = User::factory()->create();
    $test->fx['team']->members()->attach($member->id, ['role' => 'member']);

    return $member;
}

function connectForm(object $test, User $user)
{
    $test->actingAs($user);

    return Livewire::test(EnvironmentConfiguration::class, $test->params)
        ->set('connectSourceType', 'public')
        ->set('connectRepository', 'https://github.com/acme/infra')
        ->set('connectBranch', 'main')
        ->set('connectFilePath', 'coolify.json')
        ->set('connectServerUuid', $test->fx['server']->uuid);
}

it('previews the file before connecting', function () {
    connectForm($this, $this->fx['user'])
        ->call('previewConnect')
        ->assertSet('connectError', null)
        ->assertSet('connectPlan.changes.0.key', 'cache');

    expect(EnvironmentConfigLink::count())->toBe(0);
});

it('connects the environment for a team admin', function () {
    connectForm($this, $this->fx['user'])->call('previewConnect')->call('connect');

    $link = EnvironmentConfigLink::first();
    expect($link->environment_id)->toBe($this->fx['environment']->id)
        ->and($link->repository)->toBe('https://github.com/acme/infra')
        ->and($link->read_server_id)->toBe($this->fx['server']->id);
    Queue::assertPushed(PlanEnvironmentConfigJob::class);
});

it('does not let a member connect, disconnect, or change automatic apply', function () {
    connectForm($this, pageMember($this))->call('connect');
    expect(EnvironmentConfigLink::count())->toBe(0);

    $link = EnvironmentConfigLink::factory()->create(['environment_id' => $this->fx['environment']->id, 'mode' => 'git', 'branch' => 'main']);
    Livewire::test(EnvironmentConfiguration::class, $this->params)->call('disconnect')->call('setAutoApply', false);

    expect($link->fresh())->not->toBeNull()->and($link->fresh()->auto_apply)->toBeTrue();
});

it('lets an admin disconnect and change automatic apply', function () {
    $this->actingAs($this->fx['user']);
    $link = EnvironmentConfigLink::factory()->create(['environment_id' => $this->fx['environment']->id, 'mode' => 'git', 'branch' => 'main']);

    $component = Livewire::test(EnvironmentConfiguration::class, $this->params)->call('setAutoApply', false);
    expect($link->fresh()->auto_apply)->toBeFalse();

    $component->call('disconnect');
    expect(EnvironmentConfigLink::count())->toBe(0);
});

it('offers only team servers and sources', function () {
    $other = projectConfigFixture();
    session(['currentTeam' => $this->fx['team']]);
    $this->actingAs($this->fx['user']);

    $component = Livewire::test(EnvironmentConfiguration::class, $this->params);
    $servers = collect($component->viewData('connectServers'))->pluck('uuid');

    expect($servers)->toContain($this->fx['server']->uuid)->not->toContain($other['server']->uuid);
});

it('polls only while a sync is in progress', function () {
    $link = EnvironmentConfigLink::factory()->create(['environment_id' => $this->fx['environment']->id, 'mode' => 'git', 'branch' => 'main']);
    $this->actingAs($this->fx['user']);
    $page = fn () => Livewire::test(EnvironmentConfiguration::class, $this->params);

    $page()->assertSeeHtml('wire:poll.3s');

    $revision = EnvironmentConfigRevision::factory()->create(['link_id' => $link->id, 'status' => ConfigRevisionStatus::Applying]);
    $page()->assertSeeHtml('wire:poll.3s');

    $revision->update(['status' => ConfigRevisionStatus::Applied]);
    $page()->assertDontSeeHtml('wire:poll.3s');
});

it('lets only a team admin download the export', function () {
    $this->actingAs(pageMember($this));
    Livewire::test(EnvironmentConfiguration::class, $this->params)->call('downloadExport')->assertNoFileDownloaded();

    $this->actingAs($this->fx['user']);
    Livewire::test(EnvironmentConfiguration::class, $this->params)->call('downloadExport')->assertFileDownloaded('coolify.json');
});
