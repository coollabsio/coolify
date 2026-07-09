<?php

namespace App\Console\Commands\Dev;

use App\Actions\Database\StartDatabase;
use App\Actions\Database\StopDatabase;
use App\Actions\Service\StartService;
use App\Actions\Service\StopService;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Console\Command;

class DashboardDemo extends Command
{
    protected $signature = 'dev:dashboard-demo
                            {--team=0 : Team ID to use}
                            {--skip-stop : Keep all deployed resources running}';

    protected $description = 'Deploy lightweight demo services and databases for dashboard testing.';

    /**
     * @var array<int, string>
     */
    private const SERVICE_TEMPLATES_TO_DEPLOY = [
        'uptime-kuma' => 'Dashboard Demo - Uptime Kuma (running)',
        'memos' => 'Dashboard Demo - Memos (will stop)',
        'web-check' => 'Dashboard Demo - Web Check (running)',
    ];

    public function handle(): int
    {
        if (! isDev()) {
            $this->error('This command is only available in development mode.');

            return self::FAILURE;
        }

        $team = Team::query()->find($this->option('team'));
        if (! $team) {
            $this->error('Team not found.');

            return self::FAILURE;
        }

        $project = Project::query()->where('team_id', $team->id)->first();
        if (! $project) {
            $this->error('No project found for this team. Create a project first.');

            return self::FAILURE;
        }

        $environment = Environment::query()->where('project_id', $project->id)->first();
        if (! $environment) {
            $this->error('No environment found for this project.');

            return self::FAILURE;
        }

        $destination = StandaloneDocker::query()->whereHas('server', fn ($query) => $query->where('team_id', $team->id))->first();
        if (! $destination) {
            $this->error('No Docker destination found for this team.');

            return self::FAILURE;
        }

        $this->info("Using project \"{$project->name}\" / environment \"{$environment->name}\" on server \"{$destination->server->name}\".");
        $this->newLine();

        $services = get_service_templates();
        $deployedServices = [];

        foreach (self::SERVICE_TEMPLATES_TO_DEPLOY as $templateKey => $displayName) {
            $compose = data_get($services, "{$templateKey}.compose");
            if (! $compose) {
                $this->warn("Skipping missing template: {$templateKey}");

                continue;
            }

            $service = $this->createServiceFromTemplate(
                templateKey: $templateKey,
                displayName: $displayName,
                dockerComposeRaw: base64_decode($compose),
                environment: $environment,
                destination: $destination,
            );

            $this->line("Starting service: {$displayName}");
            StartService::dispatch($service);
            $deployedServices[$templateKey] = $service;
        }

        $runningRedis = create_standalone_redis($environment->id, $destination, [
            'name' => 'Dashboard Demo - Redis (running)',
            'health_check_enabled' => false,
        ]);
        $this->line('Starting database: Dashboard Demo - Redis (running)');
        StartDatabase::dispatch($runningRedis);

        $stoppedPostgres = create_standalone_postgresql($environment->id, $destination, [
            'name' => 'Dashboard Demo - PostgreSQL (will stop)',
            'health_check_enabled' => false,
        ], 'postgres:16-alpine');
        $this->line('Starting database: Dashboard Demo - PostgreSQL (will stop)');
        StartDatabase::dispatch($stoppedPostgres);

        if (! $this->option('skip-stop')) {
            $this->newLine();
            $this->info('Waiting 90 seconds for containers to start before stopping demo alerts...');
            sleep(90);

            if (isset($deployedServices['memos'])) {
                $this->line('Stopping service: Dashboard Demo - Memos (will stop)');
                StopService::dispatch($deployedServices['memos']);
            }

            $this->line('Stopping database: Dashboard Demo - PostgreSQL (will stop)');
            StopDatabase::dispatch($stoppedPostgres);
        }

        $this->newLine();
        $this->components->info('Dashboard demo resources queued.');
        $this->line('Open http://localhost:8000 and refresh the dashboard in ~2 minutes.');
        $this->line('Expected result:');
        $this->line('  - KPIs: 1 server, 1 project, multiple apps/services/databases');
        $this->line('  - Alerts: existing exited app + stopped Memos + stopped PostgreSQL');
        $this->line('  - Running: Uptime Kuma, Web Check, Redis');

        return self::SUCCESS;
    }

    private function createServiceFromTemplate(
        string $templateKey,
        string $displayName,
        string $dockerComposeRaw,
        Environment $environment,
        StandaloneDocker $destination,
    ): Service {
        validateDockerComposeForInjection($dockerComposeRaw);

        $service = Service::create([
            'name' => $displayName,
            'docker_compose_raw' => $dockerComposeRaw,
            'environment_id' => $environment->id,
            'service_type' => $templateKey,
            'server_id' => $destination->server_id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
        ]);

        $service->parse(isNew: true);
        applyServiceApplicationPrerequisites($service);

        return $service->fresh(['applications', 'databases']);
    }
}
