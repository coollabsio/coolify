<?php

use App\Enums\ConfigRevisionStatus;
use App\Jobs\ApplyEnvironmentConfigJob;
use App\Livewire\Project\EnvironmentConfiguration;
use App\Models\Application;
use App\Models\EnvironmentConfigLink;
use App\Models\EnvironmentConfigRevision;
use App\Models\EnvironmentVariable;
use App\Models\Team;
use App\Models\User;
use App\Services\ProjectConfig\ManagedResources;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake([ApplyEnvironmentConfigJob::class]);
    $this->fx = projectConfigFixture();
    $this->link = EnvironmentConfigLink::factory()->create([
        'environment_id' => $this->fx['environment']->id, 'mode' => 'git', 'repository' => 'acme/api', 'branch' => 'main',
    ]);
    $this->revision = EnvironmentConfigRevision::factory()->create([
        'link_id' => $this->link->id, 'status' => ConfigRevisionStatus::WaitingApproval, 'commit_sha' => str_repeat('a', 40),
        'plan' => ['changes' => [
            ['kind' => 'remove', 'key' => 'worker', 'path' => '', 'destructive' => true, 'secret' => false, 'from' => 'application', 'to' => null],
            ['kind' => 'update', 'key' => 'api', 'path' => 'env.TOKEN', 'destructive' => false, 'secret' => true, 'from' => null, 'to' => null],
        ], 'errors' => [], 'warnings' => ['"handmade" (application) is not managed by coolify.json.'], 'missing_required' => ['api' => ['STRIPE_KEY']]],
    ]);
    $this->params = ['project_uuid' => $this->fx['project']->uuid, 'environment_uuid' => $this->fx['environment']->uuid];
});

function configurationTabMember(object $test, string $role = 'member'): User
{
    $user = User::factory()->create();
    $test->fx['team']->members()->attach($user->id, ['role' => $role]);

    return $user;
}

it('shows the link and the waiting plan', function () {
    $this->actingAs($this->fx['user']);

    Livewire::test(EnvironmentConfiguration::class, $this->params)
        ->assertSee('acme/api')
        ->assertSee('worker')
        ->assertSee('env.TOKEN')
        ->assertSee('STRIPE_KEY')
        ->assertSee('is not managed by coolify.json');
});

it('approves with the selected data deletion', function () {
    $this->actingAs($this->fx['user']);

    Livewire::test(EnvironmentConfiguration::class, $this->params)
        ->set('deleteData', ['worker'])
        ->call('approve', $this->revision->uuid, 'password');

    $revision = $this->revision->fresh();
    expect($revision->approved_by)->toBe($this->fx['user']->id)
        ->and($revision->delete_data)->toBe(['worker']);
    Queue::assertPushed(ApplyEnvironmentConfigJob::class);
});

it('does not let a member approve or suspend', function () {
    $this->actingAs(configurationTabMember($this));

    Livewire::test(EnvironmentConfiguration::class, $this->params)
        ->assertSee('worker')
        ->call('approve', $this->revision->uuid, 'password')
        ->call('suspend');

    expect($this->revision->fresh()->approved_at)->toBeNull()
        ->and($this->link->fresh()->isSuspended())->toBeFalse();
    Queue::assertNotPushed(ApplyEnvironmentConfigJob::class);
});

it('checks the team of the link after the session team changes', function () {
    $member = configurationTabMember($this);
    $own = Team::factory()->create();
    $own->members()->attach($member->id, ['role' => 'owner']);
    $this->actingAs($member);

    $component = Livewire::test(EnvironmentConfiguration::class, $this->params);
    session(['currentTeam' => $own]);
    $component->call('approve', $this->revision->uuid, 'password')->call('suspend');

    expect($this->revision->fresh()->approved_at)->toBeNull()
        ->and($this->link->fresh()->isSuspended())->toBeFalse();
});

it('lets an admin suspend and resume', function () {
    $this->actingAs(configurationTabMember($this, 'admin'));

    $component = Livewire::test(EnvironmentConfiguration::class, $this->params)->call('suspend');
    expect($this->link->fresh()->isSuspended())->toBeTrue();

    $component->call('confirmResume');
    expect($this->link->fresh()->isSuspended())->toBeFalse();
});

it('downloads an export without values', function () {
    $app = ManagedResources::whileApplying(fn () => Application::factory()->create([
        'name' => 'api', 'environment_id' => $this->fx['environment']->id,
        'destination_id' => $this->fx['destination']->id, 'destination_type' => $this->fx['destination']->getMorphClass(),
    ]));
    ManagedResources::whileApplying(fn () => EnvironmentVariable::create(['key' => 'TOKEN', 'value' => 'very-secret', 'resourceable_type' => $app->getMorphClass(), 'resourceable_id' => $app->id]));
    $this->actingAs($this->fx['user']);

    Livewire::test(EnvironmentConfiguration::class, $this->params)
        ->call('downloadExport')
        ->assertFileDownloaded('coolify.json')
        ->assertDontSee('very-secret');
});

it('is reachable from its route', function () {
    $this->actingAs($this->fx['user']);

    $this->get(route('project.environment.configuration', $this->params))->assertOk();
});
