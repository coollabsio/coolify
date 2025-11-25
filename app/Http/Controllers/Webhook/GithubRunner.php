<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\CleanupGithubRunnerJob;
use App\Jobs\ProvisionGithubRunnerJob;
use App\Models\GitHubRunner;
use App\Models\GitHubRunnerSource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GithubRunner extends Controller
{
    public function handle(Request $request, string $sourceId)
    {
        try {
            // Find the runner source
            $source = GitHubRunnerSource::findOrFail($sourceId);

            // Get webhook headers
            $x_github_event = Str::lower($request->header('X-GitHub-Event'));
            $x_hub_signature_256 = $request->header('X-Hub-Signature-256');
            $x_github_delivery = $request->header('X-GitHub-Delivery');

            // Get payload
            $payload = $request->getContent();
            $payloadArray = json_decode($payload, true);

            // Validate webhook signature
            if (! validateRunnerWebhookSignature($payload, $x_hub_signature_256, $source->webhook_secret)) {
                Log::warning('Invalid webhook signature for GitHub Runner', [
                    'source_id' => $sourceId,
                    'delivery_id' => $x_github_delivery,
                ]);

                return response()->json(['error' => 'Invalid signature'], 401);
            }

            // Handle ping event
            if ($x_github_event === 'ping') {
                Log::info('GitHub Runner webhook ping received', ['source_id' => $sourceId]);

                return response()->json(['message' => 'pong']);
            }

            // Only handle workflow_job events
            if ($x_github_event !== 'workflow_job') {
                Log::debug('Ignoring non-workflow_job event', [
                    'event' => $x_github_event,
                    'source_id' => $sourceId,
                ]);

                return response()->json(['message' => 'Event ignored']);
            }

            $action = data_get($payloadArray, 'action');
            $workflowJob = data_get($payloadArray, 'workflow_job');

            // Handle different workflow_job actions
            switch ($action) {
                case 'queued':
                    $this->handleJobQueued($source, $workflowJob, $payloadArray);
                    break;

                case 'in_progress':
                    // Job is running, we can update status if needed
                    $this->handleJobInProgress($workflowJob);
                    break;

                case 'completed':
                    // Job completed, trigger cleanup
                    $this->handleJobCompleted($workflowJob);
                    break;

                default:
                    Log::debug('Unknown workflow_job action', [
                        'action' => $action,
                        'source_id' => $sourceId,
                    ]);
            }

            return response()->json(['message' => 'Webhook processed']);
        } catch (\Exception $e) {
            Log::error('Error processing GitHub Runner webhook', [
                'error' => $e->getMessage(),
                'source_id' => $sourceId ?? null,
            ]);

            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    private function handleJobQueued(GitHubRunnerSource $source, array $workflowJob, array $payload): void
    {
        $labels = data_get($workflowJob, 'labels', []);
        $jobId = data_get($workflowJob, 'id');
        $workflowName = data_get($workflowJob, 'workflow_name');
        $repositoryName = data_get($payload, 'repository.full_name');

        // Check if any of the labels match this source's runner label
        if (! in_array($source->runner_label, $labels)) {
            Log::debug('Job labels do not match this source', [
                'source_label' => $source->runner_label,
                'job_labels' => $labels,
                'source_id' => $source->id,
            ]);

            return;
        }

        Log::info('Provisioning runner for job', [
            'source_id' => $source->id,
            'job_id' => $jobId,
            'workflow' => $workflowName,
            'repository' => $repositoryName,
        ]);

        // Dispatch job to provision a runner
        ProvisionGithubRunnerJob::dispatch($source, $workflowJob, $payload);
    }

    private function handleJobInProgress(array $workflowJob): void
    {
        $jobId = data_get($workflowJob, 'id');

        // Find the runner record and update status
        $runner = GitHubRunner::where('job_id', $jobId)->first();
        if ($runner && $runner->status !== 'running') {
            $runner->markAsRunning();

            Log::info('Runner job started', [
                'runner_id' => $runner->id,
                'job_id' => $jobId,
            ]);
        }
    }

    private function handleJobCompleted(array $workflowJob): void
    {
        $jobId = data_get($workflowJob, 'id');
        $conclusion = data_get($workflowJob, 'conclusion'); // success, failure, cancelled, etc.

        // Find the runner record
        $runner = GitHubRunner::where('job_id', $jobId)->first();
        if (! $runner) {
            Log::debug('No runner found for completed job', ['job_id' => $jobId]);

            return;
        }

        // Update runner status based on conclusion
        if ($conclusion === 'success') {
            $runner->markAsCompleted();
        } else {
            $runner->markAsFailed();
        }

        Log::info('Runner job completed', [
            'runner_id' => $runner->id,
            'job_id' => $jobId,
            'conclusion' => $conclusion,
        ]);

        // Dispatch cleanup job
        CleanupGithubRunnerJob::dispatch($runner);
    }
}
