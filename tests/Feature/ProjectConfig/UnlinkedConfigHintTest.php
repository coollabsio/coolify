<?php

use App\Livewire\Project\Application\General;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\EnvironmentConfigLink;
use App\Services\ProjectConfig\UnlinkedConfigFileHint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->fx = projectConfigFixture();
    $this->application = Application::factory()->create([
        'name' => 'api', 'static_image' => 'nginx:alpine', 'build_pack' => 'dockerfile',
        'environment_id' => $this->fx['environment']->id,
        'destination_id' => $this->fx['destination']->id, 'destination_type' => $this->fx['destination']->getMorphClass(),
    ])->fresh();
    $this->deployment = ApplicationDeploymentQueue::create([
        'application_id' => $this->application->id, 'deployment_uuid' => 'dep1', 'status' => 'in_progress',
        'server_id' => $this->fx['server']->id, 'destination_id' => $this->fx['destination']->id,
    ]);
});

it('checks the repository root for coolify.json', function () {
    expect(app(UnlinkedConfigFileHint::class)->command('dep1', '/artifacts/dep1'))
        ->toContain('/artifacts/dep1/coolify.json');
});

it('logs the hint and flags the application when the file exists and nothing is linked', function () {
    $hint = app(UnlinkedConfigFileHint::class);

    expect($hint->shouldCheck($this->application, 0))->toBeTrue();
    $hint->record($this->application, $this->deployment, 'exists');

    expect($this->application->settings->fresh()->has_unlinked_config_file)->toBeTrue()
        ->and(json_encode($this->deployment->fresh()->logs))->toContain('coolify.json found, not linked');

    $hint->record($this->application, $this->deployment, 'missing');
    expect($this->application->settings->fresh()->has_unlinked_config_file)->toBeFalse();
});

it('does not check a linked environment, a managed app, or a preview', function () {
    $hint = app(UnlinkedConfigFileHint::class);
    expect($hint->shouldCheck($this->application, 3))->toBeFalse();

    EnvironmentConfigLink::factory()->create(['environment_id' => $this->fx['environment']->id]);
    expect($hint->shouldCheck($this->application->fresh(), 0))->toBeFalse();
});

it('shows the hint on the General page', function () {
    $this->application->settings->forceFill(['has_unlinked_config_file' => true])->save();
    $this->actingAs($this->fx['user']);

    Livewire::test(General::class, ['application' => $this->application->fresh()])
        ->assertSee('coolify.json found, not linked');
});
