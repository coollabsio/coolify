<?php

use App\Jobs\DeleteResourceJob;
use App\Livewire\Project\DeleteEnvironment;
use App\Livewire\Project\Shared\Danger;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('constants.horizon.is_horizon_enabled', false);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('deletes resources in selected environment only', function () {
    Queue::fake();

    $project = Project::query()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'project-a',
        'team_id' => $this->team->id,
    ]);

    $environmentToDelete = Environment::query()->create([
        'name' => 'production',
        'project_id' => $project->id,
    ]);

    $otherEnvironment = Environment::query()->create([
        'name' => 'staging',
        'project_id' => $project->id,
    ]);

    $resourceInTargetEnvironment = Service::query()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'service-production',
        'destination_type' => 'App\\Models\\StandaloneDocker',
        'destination_id' => 1,
        'environment_id' => $environmentToDelete->id,
    ]);

    $resourceInOtherEnvironment = Service::query()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'service-staging',
        'destination_type' => 'App\\Models\\StandaloneDocker',
        'destination_id' => 1,
        'environment_id' => $otherEnvironment->id,
    ]);

    Livewire::test(DeleteEnvironment::class, ['environment_id' => $environmentToDelete->id])
        ->set('parameters', ['project_uuid' => $project->uuid])
        ->call('delete');

    expect(Environment::query()->find($environmentToDelete->id))->toBeNull();
    expect(Service::withTrashed()->find($resourceInTargetEnvironment->id)?->trashed())->toBeTrue();
    expect(Service::query()->find($resourceInOtherEnvironment->id))->not->toBeNull();

    Queue::assertPushed(DeleteResourceJob::class, 1);
});

it('danger delete method accepts checkbox selection payload', function () {
    $reflection = new ReflectionMethod(Danger::class, 'delete');

    expect($reflection->getNumberOfParameters())->toBe(2);
    expect($reflection->getParameters()[1]->getName())->toBe('selectedActions');
    expect($reflection->getParameters()[1]->isOptional())->toBeTrue();
});

