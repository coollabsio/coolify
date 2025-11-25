<?php

namespace App\Jobs;

use App\Models\GitHubRunner;
use App\Models\GitHubRunnerSource;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProvisionGithubRunnerJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public GitHubRunnerSource $source,
        public array $workflowJob,
        public array $payload
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        try {
            // Extract job details
            $jobId = data_get($this->workflowJob, 'id');
            $workflowName = data_get($this->workflowJob, 'workflow_name');
            $repositoryName = data_get($this->payload, 'repository.full_name');

            Log::info('Starting runner provisioning', [
                'source_id' => $this->source->id,
                'job_id' => $jobId,
                'workflow' => $workflowName,
            ]);

            // Step 1: Select an available server from the pool
            $server = $this->source->getAvailableServers()->first();

            if (! $server) {
                Log::error('No available servers in pool', [
                    'source_id' => $this->source->id,
                    'job_id' => $jobId,
                ]);

                return;
            }

            Log::info('Selected server for runner', [
                'server_id' => $server->id,
                'server_name' => $server->name,
            ]);

            // Step 2: Generate unique runner name
            $runnerName = 'coolify-runner-'.Str::random(8);

            // Step 3: Generate JIT configuration
            $labels = [$this->source->runner_label, 'self-hosted', 'coolify'];
            $jitConfig = generateRunnerJitConfig($this->source, $labels, $runnerName);

            if (! $jitConfig) {
                Log::error('Failed to generate JIT config', [
                    'source_id' => $this->source->id,
                    'job_id' => $jobId,
                ]);

                return;
            }

            $encodedJitConfig = data_get($jitConfig, 'encoded_jit_config');
            $githubRunnerId = data_get($jitConfig, 'runner.id');

            // Step 4: Create runner record in database
            $runner = GitHubRunner::create([
                'github_runner_source_id' => $this->source->id,
                'server_id' => $server->id,
                'runner_id' => $githubRunnerId,
                'runner_name' => $runnerName,
                'job_id' => $jobId,
                'workflow_name' => $workflowName,
                'repository_name' => $repositoryName,
                'status' => 'queued',
            ]);

            Log::info('Created runner record', [
                'runner_id' => $runner->id,
                'runner_name' => $runnerName,
            ]);

            // Step 5: Provision Docker container on the server
            $this->provisionDockerRunner($server, $runner, $encodedJitConfig);

            Log::info('Runner provisioned successfully', [
                'runner_id' => $runner->id,
                'server_id' => $server->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Error provisioning runner', [
                'error' => $e->getMessage(),
                'source_id' => $this->source->id,
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function provisionDockerRunner($server, GitHubRunner $runner, string $encodedJitConfig): void
    {
        $containerName = 'coolify-runner-'.$runner->uuid;

        // Update runner record with container name
        $runner->update(['container_name' => $containerName]);

        // Build Docker run command
        $command = sprintf(
            'docker run -d --rm --name %s -e EPHEMERAL=true -e JIT_CONFIG=%s -v /var/run/docker.sock:/var/run/docker.sock myoung34/github-runner:latest',
            escapeshellarg($containerName),
            escapeshellarg($encodedJitConfig)
        );

        // Execute command on the server
        $result = instant_remote_process([$command], $server, false);

        if (! $result) {
            throw new \Exception('Failed to start Docker container on server');
        }

        Log::info('Docker container started', [
            'container_name' => $containerName,
            'server_id' => $server->id,
        ]);
    }
}
