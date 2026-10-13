<?php

use App\Actions\Application\CleanupPreviewDeployment;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $this->preview = ApplicationPreview::create([
        'application_id' => $this->application->id,
        'pull_request_id' => 42,
        'pull_request_html_url' => 'https://github.com/example/repository/pull/42',
        'fqdn' => 'https://pr-42.example.com',
    ]);

    Process::fake(['*' => Process::result(output: '')]);
    Queue::fake();
});

function createPreviewDeploymentsForQueueAdvancementTest(): array
{
    $activeDeployment = ApplicationDeploymentQueue::create([
        'application_id' => test()->application->id,
        'deployment_uuid' => 'preview-active-'.fake()->uuid(),
        'status' => 'in_progress',
        'server_id' => test()->server->id,
        'destination_id' => test()->destination->id,
        'commit' => 'preview-commit',
        'pull_request_id' => 42,
    ]);
    $nextDeployment = ApplicationDeploymentQueue::create([
        'application_id' => test()->application->id,
        'deployment_uuid' => 'preview-next-'.fake()->uuid(),
        'status' => 'queued',
        'server_id' => test()->server->id,
        'destination_id' => test()->destination->id,
        'commit' => 'next-commit',
        'pull_request_id' => 0,
    ]);

    return [$activeDeployment, $nextDeployment];
}

test('preview cleanup advances the deployment queue after cancelling active deployments', function () {
    [$activeDeployment, $nextDeployment] = createPreviewDeploymentsForQueueAdvancementTest();

    CleanupPreviewDeployment::run($this->application, 42, $this->preview);

    expect($activeDeployment->fresh()->status)->toBe('cancelled-by-user')
        ->and($nextDeployment->fresh()->status)->toBe('in_progress');
    Queue::assertPushed(ApplicationDeploymentJob::class, fn (ApplicationDeploymentJob $job) => $job->application_deployment_queue_id === $nextDeployment->id);
});

test('deleting a preview advances the deployment queue after cancelling active deployments', function () {
    [$activeDeployment, $nextDeployment] = createPreviewDeploymentsForQueueAdvancementTest();

    (new DeleteResourceJob($this->preview))->handle();

    expect($activeDeployment->fresh()->status)->toBe('cancelled-by-user')
        ->and($nextDeployment->fresh()->status)->toBe('in_progress')
        ->and(ApplicationPreview::withTrashed()->find($this->preview->id))->toBeNull();
    Queue::assertPushed(ApplicationDeploymentJob::class, fn (ApplicationDeploymentJob $job) => $job->application_deployment_queue_id === $nextDeployment->id);
});
