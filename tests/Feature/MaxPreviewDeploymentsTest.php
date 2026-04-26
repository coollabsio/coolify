<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Jobs\ApplicationDeploymentJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Fake the deployment job so dispatch() does not actually run handle().
    // Without this, ApplicationDeploymentJob (ShouldBeEncrypted + ShouldQueue) executes synchronously
    // under QUEUE_CONNECTION=sync and tries to SSH to a non-existent server.
    Bus::fake([ApplicationDeploymentJob::class]);
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
});

function deployPr(Application $application, int $prId): array
{
    // Mirror production flow: ProcessGithubPullRequestWebhook always creates the
    // ApplicationPreview row before invoking queue_application_deployment().
    // Without it, ApplicationDeploymentJob::__construct() throws on findPreviewByApplicationAndPullId.
    ApplicationPreview::firstOrCreate(
        ['application_id' => $application->id, 'pull_request_id' => $prId],
        ['pull_request_html_url' => "https://example.test/pr/{$prId}", 'git_type' => 'github']
    );

    return queue_application_deployment(
        application: $application,
        deployment_uuid: (new Cuid2)->toString(),
        pull_request_id: $prId,
        commit: 'sha-'.$prId,
        is_webhook: true,
        git_type: 'github'
    );
}

function makeRunningPreview(Application $application, int $prId): ApplicationPreview
{
    return ApplicationPreview::create([
        'application_id' => $application->id,
        'pull_request_id' => $prId,
        'pull_request_html_url' => "https://example.test/pr/{$prId}",
        'git_type' => 'github',
        'status' => 'running',
    ]);
}

function makeQueuedDeployment(Application $application, int $prId, string $status = 'queued'): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'server_id' => $application->destination->server->id,
        'server_name' => $application->destination->server->name,
        'destination_id' => $application->destination->id,
        'deployment_uuid' => (new Cuid2)->toString(),
        'deployment_url' => '/test',
        'pull_request_id' => $prId,
        'force_rebuild' => false,
        'is_webhook' => true,
        'is_api' => false,
        'restart_only' => false,
        'commit' => 'sha-'.$prId,
        'rollback' => false,
        'git_type' => 'github',
        'only_this_server' => false,
        'status' => $status,
    ]);
}

describe('Max Preview Deployments gate', function () {
    test('with limit=0 (unlimited), PR deployments queue normally', function () {
        $this->application->settings->max_preview_deployments = 0;
        $this->application->settings->save();

        // Even with 5 running previews, a new PR should still queue.
        for ($i = 1; $i <= 5; $i++) {
            makeRunningPreview($this->application, $i);
        }

        $result = deployPr($this->application, 99);

        expect($result['status'])->toBe('queued');
    });

    test('with limit=2 and no active previews, deployment queues', function () {
        $this->application->settings->max_preview_deployments = 2;
        $this->application->settings->save();

        $result = deployPr($this->application, 1);

        expect($result['status'])->toBe('queued');
    });

    test('with limit=2 and 2 running previews, new PR is skipped', function () {
        $this->application->settings->max_preview_deployments = 2;
        $this->application->settings->save();

        makeRunningPreview($this->application, 1);
        makeRunningPreview($this->application, 2);

        $result = deployPr($this->application, 3);

        expect($result['status'])->toBe('skipped');
        expect($result['message'])->toContain('Max simultaneous preview deployments');
    });

    test('limit counts both running previews and queued deployments for OTHER PRs', function () {
        $this->application->settings->max_preview_deployments = 2;
        $this->application->settings->save();

        makeRunningPreview($this->application, 1);
        makeQueuedDeployment($this->application, 2);

        $result = deployPr($this->application, 3);

        expect($result['status'])->toBe('skipped');
    });

    test('a re-sync of an already-queued PR is not blocked by its own queued row', function () {
        $this->application->settings->max_preview_deployments = 2;
        $this->application->settings->save();

        makeRunningPreview($this->application, 1);
        makeQueuedDeployment($this->application, 5); // PR 5 already queued — total active = 2

        // Re-syncing PR 5 must not count PR 5's own queued row toward the cap.
        // The downstream existing-deployment guard may still return 'skipped' for the
        // duplicate commit, so we assert by message that the cap-gate is what did NOT fire.
        $result = deployPr($this->application, 5);

        expect($result['message'] ?? '')->not->toContain('Max simultaneous preview deployments');
    });

    test('non-PR deployments (pull_request_id=0) are never gated', function () {
        $this->application->settings->max_preview_deployments = 1;
        $this->application->settings->save();

        makeRunningPreview($this->application, 1);
        makeRunningPreview($this->application, 2);

        $result = queue_application_deployment(
            application: $this->application,
            deployment_uuid: (new Cuid2)->toString(),
            pull_request_id: 0,
            commit: 'main-sha',
            is_webhook: true,
            git_type: 'github'
        );

        expect($result['status'])->toBe('queued');
    });
});
