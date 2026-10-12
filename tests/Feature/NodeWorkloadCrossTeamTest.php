<?php

use App\Jobs\DeleteNodeWorkloadJob;
use App\Livewire\Project\DeleteProject;
use App\Livewire\Project\Shared\Danger;
use App\Livewire\Project\Shared\EnvironmentVariable\All as EnvironmentVariableAll;
use App\Livewire\Project\Shared\EnvironmentVariable\Show as EnvironmentVariableShow;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\NodeWorkload;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Queue::fake();

    $owner = User::factory()->create();
    $this->otherTeam = $owner->teams()->firstOrFail();
    $otherProject = Project::factory()->create(['team_id' => $this->otherTeam->id]);
    $otherEnvironment = Environment::factory()->create(['project_id' => $otherProject->id]);
    $this->foreignWorkload = NodeWorkload::factory()->create([
        'team_id' => $this->otherTeam->id,
        'project_id' => $otherProject->id,
        'environment_id' => $otherEnvironment->id,
        'name' => 'foreign',
    ]);
    $this->foreignVariable = $this->foreignWorkload->environment_variables()->create(['key' => 'SECRET', 'value' => 'original']);

    $this->user = User::factory()->create();
    $this->team = $this->user->teams()->firstOrFail();
    $this->team->update(['show_boarding' => false]);
    Cache::flush();
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('does not let an admin of another team delete the workload from the danger zone', function (string $method) {
    Livewire::test(Danger::class, ['resource' => $this->foreignWorkload])
        ->assertSet('canDelete', false)
        ->call($method, 'password')
        ->assertDispatched('error', 'This action is unauthorized.')
        ->assertNoRedirect();

    Queue::assertNotPushed(DeleteNodeWorkloadJob::class);
    expect($this->foreignWorkload->fresh())->not->toBeNull();
})->with(['delete', 'deleteFromCoolifyOnly']);

it('does not let an admin of another team add or replace environment variables', function () {
    $component = Livewire::test(EnvironmentVariableAll::class, ['resource' => $this->foreignWorkload]);

    $component->call('submit', ['key' => 'INJECTED', 'value' => 'x'])
        ->assertDispatched('error', 'This action is unauthorized.');
    $component->set('variables', 'INJECTED=x')
        ->call('submit')
        ->assertDispatched('error', 'This action is unauthorized.');

    $variables = EnvironmentVariable::query()
        ->where('resourceable_type', $this->foreignWorkload->getMorphClass())
        ->where('resourceable_id', $this->foreignWorkload->id)
        ->get();
    expect($variables->pluck('key')->all())->toBe(['SECRET'])
        ->and($variables->sole()->value)->toBe('original');
});

it('does not let an admin of another team update or delete an environment variable', function () {
    $component = Livewire::test(EnvironmentVariableShow::class, ['env' => $this->foreignVariable, 'type' => 'cluster-application']);

    expect(fn () => $component->set('value', 'tampered')->call('submit'))
        ->toThrow(Exception::class, 'This action is unauthorized.');
    expect(fn () => $component->call('delete'))
        ->toThrow(Exception::class, 'This action is unauthorized.');

    expect($this->foreignVariable->fresh()->value)->toBe('original');
});

it('resolves the same owning team in the workload and environment variable policies', function () {
    $admin = User::query()->whereHas('teams', fn ($query) => $query->whereKey($this->otherTeam->id))->firstOrFail();

    expect($admin->can('manageEnvironment', $this->foreignWorkload))->toBeTrue()
        ->and($admin->can('update', $this->foreignVariable))->toBeTrue()
        ->and($this->user->can('manageEnvironment', $this->foreignWorkload))->toBeFalse()
        ->and($this->user->can('update', $this->foreignVariable))->toBeFalse();
});

it('refuses to delete a project that still contains cluster applications', function () {
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $workload = NodeWorkload::factory()->create([
        'team_id' => $this->team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
    ]);

    Livewire::test(DeleteProject::class, ['project_id' => $project->id])
        ->call('delete')
        ->assertDispatched('error');

    expect(Project::query()->whereKey($project->id)->exists())->toBeTrue()
        ->and($workload->fresh()->environment_id)->toBe($environment->id);
});

it('keeps both policies in agreement after the project of a workload is deleted', function () {
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $workload = NodeWorkload::factory()->create([
        'team_id' => $this->team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
    ]);
    $variable = $workload->environment_variables()->create(['key' => 'APP_ENV', 'value' => 'production']);

    $project->delete();
    $workload->refresh();
    $variable->refresh();

    expect($this->user->can('manageEnvironment', $workload))
        ->toBe($this->user->can('update', $variable));
});
