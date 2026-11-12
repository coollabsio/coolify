<?php

use App\Exceptions\DeploymentException;
use App\Exceptions\PreviewApprovalRequiredException;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\ApplicationPullRequestUpdateJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Team;
use App\Notifications\Application\PreviewApprovalRequired;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

const FORK_GATE_COMMIT = 'abcdef1234567890abcdef1234567890abcdef12';
const FORK_GATE_OLD_COMMIT = '1111111111111111111111111111111111111111';

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Notification::fake();
    Queue::fake([ApplicationPullRequestUpdateJob::class]);
});

/**
 * @param  array<string, mixed>  $settings
 * @param  array<string, mixed>  $preview
 * @return array{0: ApplicationDeploymentJob, 1: ApplicationPreview, 2: ApplicationDeploymentQueue, 3: Team}
 */
function forkGateJob(array $preview, array $settings = [], string $checkedOutCommit = FORK_GATE_COMMIT): array
{
    $team = Team::factory()->create();
    $team->emailNotificationSettings->update(['smtp_enabled' => true, 'deployment_failure_email_notifications' => true]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create(['environment_id' => $environment->id, 'build_pack' => 'dockercompose']);
    $application->settings()->update($settings + [
        'is_preview_deployments_enabled' => true,
        'is_pr_deployments_public_enabled' => true,
    ]);
    $application = $application->fresh();

    $previewModel = ApplicationPreview::create(['application_id' => $application->id, 'pull_request_id' => 7, 'pull_request_html_url' => 'https://github.com/acme/app/pull/7']);
    $previewModel->forceFill($preview)->save();

    $queue = ApplicationDeploymentQueue::create([
        'application_id' => (string) $application->id,
        'deployment_uuid' => 'fork-gate-deployment',
        'pull_request_id' => 7,
        'commit' => 'HEAD',
        'status' => 'in_progress',
    ]);

    $job = new class extends ApplicationDeploymentJob
    {
        public string $checkedOutCommit = '';

        public int $remoteCommands = 0;

        public function __construct() {}

        public function execute_remote_command(...$commands)
        {
            $this->remoteCommands++;
            (new ReflectionProperty(ApplicationDeploymentJob::class, 'saved_outputs'))->getValue($this)
                ->put('checked_out_commit', str($this->checkedOutCommit."\n"));
        }
    };
    $job->checkedOutCommit = $checkedOutCommit;

    foreach ([
        'application' => $application,
        'preview' => $previewModel,
        'application_deployment_queue' => $queue,
        'deployment_uuid' => 'fork-gate-deployment',
        'pull_request_id' => 7,
        'commit' => 'HEAD',
        'basedir' => '/artifacts/fork-gate-deployment',
        'saved_outputs' => collect(),
    ] as $property => $value) {
        (new ReflectionProperty(ApplicationDeploymentJob::class, $property))->setValue($job, $value);
    }

    return [$job, $previewModel, $queue, $team];
}

function runForkGate(ApplicationDeploymentJob $job): void
{
    (new ReflectionMethod(ApplicationDeploymentJob::class, 'ensureForkPreviewCommitIsApproved'))->invoke($job);
}

it('stops an unapproved fork commit, marks it as waiting, and notifies the team', function () {
    [$job, $preview, $queue, $team] = forkGateJob(['is_fork_pull_request' => true]);

    expect(fn () => runForkGate($job))->toThrow(PreviewApprovalRequiredException::class);

    expect($preview->fresh()->pending_approval_commit)->toBe(FORK_GATE_COMMIT)
        ->and($preview->fresh()->approvalStatus())->toBe('waiting_for_approval')
        ->and($queue->fresh()->commit)->toBe(FORK_GATE_COMMIT);
    Notification::assertSentTo($team, PreviewApprovalRequired::class, fn (PreviewApprovalRequired $notification) => $notification->commit === FORK_GATE_COMMIT);
});

it('deploys the fork commit that an admin approved', function () {
    [$job, $preview] = forkGateJob(['is_fork_pull_request' => true, 'approved_commit' => FORK_GATE_COMMIT]);

    runForkGate($job);

    expect($preview->fresh()->pending_approval_commit)->toBeNull();
    Notification::assertNothingSent();
});

it('needs a new approval when a push changes the commit after an approval', function () {
    [$job, $preview] = forkGateJob(['is_fork_pull_request' => true, 'approved_commit' => FORK_GATE_OLD_COMMIT]);

    expect(fn () => runForkGate($job))->toThrow(PreviewApprovalRequiredException::class);

    expect($preview->fresh()->pending_approval_commit)->toBe(FORK_GATE_COMMIT)
        ->and($preview->fresh()->approved_commit)->toBe(FORK_GATE_OLD_COMMIT);
});

it('does not check pull requests from the same repository', function () {
    [$job, $preview] = forkGateJob(['is_fork_pull_request' => false]);

    runForkGate($job);

    expect($job->remoteCommands)->toBe(0)
        ->and($preview->fresh()->pending_approval_commit)->toBeNull();
});

it('deploys fork commits without approval when the admin turned the approval off', function () {
    [$job, $preview] = forkGateJob(['is_fork_pull_request' => true], ['is_pr_deployments_public_approval_required' => false]);

    runForkGate($job);

    expect($preview->fresh()->pending_approval_commit)->toBeNull();
});

it('still needs an approval for a fork preview when public previews are off', function () {
    [$job] = forkGateJob(['is_fork_pull_request' => true], [
        'is_pr_deployments_public_enabled' => false,
        'is_pr_deployments_public_approval_required' => false,
    ]);

    expect(fn () => runForkGate($job))->toThrow(PreviewApprovalRequiredException::class);
});

it('fails when the checked out commit cannot be read', function () {
    [$job, $preview] = forkGateJob(['is_fork_pull_request' => true], [], 'fatal: not a git repository');

    expect(fn () => runForkGate($job))->toThrow(DeploymentException::class, 'cannot read the commit');

    expect($preview->fresh()->pending_approval_commit)->toBeNull();
});

it('requires approval by default for applications that already deploy public pull requests', function () {
    [, $preview] = forkGateJob(['is_fork_pull_request' => true]);

    expect($preview->application->settings->is_pr_deployments_public_approval_required)->toBeTrue()
        ->and($preview->forkCommitNeedsApproval(FORK_GATE_COMMIT))->toBeTrue();
});
