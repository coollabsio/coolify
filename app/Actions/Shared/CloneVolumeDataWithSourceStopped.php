<?php

namespace App\Actions\Shared;

use App\Actions\Application\StopApplication;
use App\Actions\Database\StartDatabase;
use App\Actions\Database\StopDatabase;
use App\Actions\Service\StartService;
use App\Actions\Service\StopService;
use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\VolumeCloneJob;
use App\Models\Application;
use App\Models\Service;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\StandaloneSqlite;
use App\Notifications\Internal\GeneralNotification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

/**
 * Copies the volume data of a clone in one queued chain. A running source is stopped before the
 * copy and started again afterwards, also when a step fails. A stopped source stays stopped.
 * When a step fails, the team gets a notification.
 */
class CloneVolumeDataWithSourceStopped
{
    use AsAction;

    /** Exception code of a failed stop step: no volume data was copied. */
    private const STOP_FAILED = 1001;

    /** Exception code of a failed stop of an application that took down some, not all, of its containers. */
    private const APPLICATION_PARTLY_STOPPED = 1002;

    /**
     * @param  array<int, VolumeCloneJob>  $volumeJobs
     */
    public function handle(
        Application|Service|StandalonePostgresql|StandaloneRedis|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse|StandaloneSqlite $source,
        array $volumeJobs,
    ): void {
        if ($volumeJobs === []) {
            return;
        }

        $wasRunning = filled($source->status) && ! $source->isExited();

        // One closure per line: a serialized closure is found by its line, so two closures on one line run the same code.
        $chain = $wasRunning ? [
            static fn () => self::stopSource($source),
            ...$volumeJobs,
            static fn () => self::startSource($source),
        ] : $volumeJobs;

        // The jobs load the new volume rows, so they must not run before the transaction of the caller commits.
        DB::afterCommit(fn () => Bus::chain($chain)
            ->onQueue('high')
            ->catch(static fn (Throwable $e) => self::recoverSource($source, $wasRunning, $e))
            ->dispatch());
    }

    /**
     * The policy ability that allows a user to stop and start the source.
     */
    public static function sourceAbility(
        Application|Service|StandaloneClickhouse|StandaloneDragonfly|StandaloneKeydb|StandaloneMariadb|StandaloneMongodb|StandaloneMysql|StandalonePostgresql|StandaloneRedis|StandaloneSqlite $source,
    ): string {
        return match (true) {
            $source instanceof Application => 'deploy',
            $source instanceof Service => 'stop',
            default => 'manage',
        };
    }

    /**
     * Stops the source and fails the chain, so that no copy runs, when the stop reports an error
     * or a container of the source still runs. The stop actions return their errors instead of throwing.
     */
    public static function stopSource(
        Application|Service|StandaloneClickhouse|StandaloneDragonfly|StandaloneKeydb|StandaloneMariadb|StandaloneMongodb|StandaloneMysql|StandalonePostgresql|StandaloneRedis|StandaloneSqlite $source,
    ): void {
        // A partly stopped application is redeployed, so remember which of its containers ran.
        $runningBefore = $source instanceof Application ? self::runningContainersOrNull($source) : null;
        $result = match (true) {
            $source instanceof Application => StopApplication::run($source, false, false),
            $source instanceof Service => StopService::run($source, dockerCleanup: false),
            default => StopDatabase::run($source, dockerCleanup: false),
        };
        if (filled($result) && $result !== 'Database stopped successfully') {
            throw new RuntimeException($result, self::failedStopCode($source, $runningBefore));
        }

        try {
            $runningContainers = self::runningContainers($source);
        } catch (Throwable $e) {
            throw new RuntimeException('Could not check that the containers stopped: '.$e->getMessage(), self::STOP_FAILED, $e);
        }
        if ($runningContainers !== []) {
            throw new RuntimeException('These containers still run: '.implode(', ', $runningContainers), self::failedStopCode($source, $runningBefore, $runningContainers));
        }
    }

    /**
     * An application whose stop took down some of its containers, while others still run, is
     * partly stopped. When the containers before the stop are unknown, it may be partly stopped.
     *
     * @param  list<string>|null  $runningBefore
     * @param  list<string>|null  $runningAfter
     */
    private static function failedStopCode(
        Application|Service|StandaloneClickhouse|StandaloneDragonfly|StandaloneKeydb|StandaloneMariadb|StandaloneMongodb|StandaloneMysql|StandalonePostgresql|StandaloneRedis|StandaloneSqlite $source,
        ?array $runningBefore,
        ?array $runningAfter = null,
    ): int {
        if (! $source instanceof Application) {
            return self::STOP_FAILED;
        }
        $runningAfter ??= self::runningContainersOrNull($source);
        if (blank($runningAfter)) {
            return self::STOP_FAILED;
        }

        return $runningBefore === null || array_diff($runningBefore, $runningAfter) !== []
            ? self::APPLICATION_PARTLY_STOPPED
            : self::STOP_FAILED;
    }

    /**
     * @return list<string>|null
     */
    private static function runningContainersOrNull(Application $application): ?array
    {
        try {
            return self::runningContainers($application);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The names of the running containers of the source on its main server. A swarm stack is
     * removed asynchronously, so it is not checked.
     *
     * @return list<string>
     */
    private static function runningContainers(
        Application|Service|StandaloneClickhouse|StandaloneDragonfly|StandaloneKeydb|StandaloneMariadb|StandaloneMongodb|StandaloneMysql|StandalonePostgresql|StandaloneRedis|StandaloneSqlite $source,
    ): array {
        $server = $source->destination->server;
        if ($server->isSwarm()) {
            return [];
        }
        [$type, $filters] = match (true) {
            $source instanceof Application => ['application', ['label=coolify.pullRequestId=0']],
            $source instanceof Service => ['service', []],
            default => ['database', []],
        };
        $output = instant_remote_process(dockerPsByOwnerCommands($type, $source->uuid, $filters, '{{.Names}}', all: false), $server);

        return collect(explode("\n", (string) $output))->map(fn (string $name) => trim($name))->filter()->unique()->values()->all();
    }

    public static function startSource(
        Application|Service|StandaloneClickhouse|StandaloneDragonfly|StandaloneKeydb|StandaloneMariadb|StandaloneMongodb|StandaloneMysql|StandalonePostgresql|StandaloneRedis|StandaloneSqlite $source,
    ): void {
        match (true) {
            $source instanceof Application => queue_application_deployment(
                application: $source,
                deployment_uuid: new_public_id(),
                server: $source->destination->server,
                destination: $source->destination,
                no_questions_asked: true,
            ),
            $source instanceof Service => StartService::run($source),
            default => StartDatabase::run($source),
        };
    }

    public static function recoverSource(
        Application|Service|StandaloneClickhouse|StandaloneDragonfly|StandaloneKeydb|StandaloneMariadb|StandaloneMongodb|StandaloneMysql|StandalonePostgresql|StandaloneRedis|StandaloneSqlite $source,
        bool $wasRunning,
        Throwable $e,
    ): void {
        if (in_array($e->getCode(), [self::STOP_FAILED, self::APPLICATION_PARTLY_STOPPED], true)) {
            self::recoverFromFailedStop($source, $e);

            return;
        }

        $message = "Copying the volume data of '{$source->name}' to its clone failed. The clone may have no or partial data.";

        if ($wasRunning) {
            try {
                self::startSource($source);
                $message .= " Coolify started '{$source->name}' again.";
            } catch (Throwable $startError) {
                Log::error("Failed to start {$source->name} again after a failed volume clone: ".$startError->getMessage());
                $message .= " Coolify could not start '{$source->name}' again. Start it manually.";
            }
        }

        $source->team()?->notify(new GeneralNotification(
            $message.' Error: '.remove_iip($e->getMessage()),
            success: false,
        ));
    }

    /**
     * No data was copied. A source that the stop took down fully (or whose state is unknown) is
     * started again. When containers of a service still run, only its stopped containers are
     * started again; an application or a database that still runs is left alone.
     */
    private static function recoverFromFailedStop(
        Application|Service|StandaloneClickhouse|StandaloneDragonfly|StandaloneKeydb|StandaloneMariadb|StandaloneMongodb|StandaloneMysql|StandalonePostgresql|StandaloneRedis|StandaloneSqlite $source,
        Throwable $e,
    ): void {
        $message = "Coolify did not copy the volume data of '{$source->name}' to its clone, because stopping '{$source->name}' failed. The clone has no copied data.";

        try {
            $stillRunning = self::runningContainers($source) !== [];
        } catch (Throwable) {
            $stillRunning = false;
        }

        if ($stillRunning && $source instanceof Service) {
            try {
                self::startStoppedServiceContainers($source);
                $message .= " Some containers of '{$source->name}' were still running, so Coolify started its stopped containers again.";
            } catch (Throwable $startError) {
                Log::error("Failed to start the stopped containers of {$source->name} after a failed stop for a volume clone: ".$startError->getMessage());
                $message .= " Some containers of '{$source->name}' are still running. Coolify could not start its stopped containers again. Start '{$source->name}' manually.";
            }
        } elseif ($stillRunning && $source instanceof Application && $e->getCode() === self::APPLICATION_PARTLY_STOPPED) {
            $message .= ' '.self::redeployPartlyStoppedApplication($source);
        } elseif ($stillRunning) {
            $message .= " '{$source->name}' is still running.";
        } else {
            try {
                self::startSource($source);
                $message .= " Coolify started '{$source->name}' again.";
            } catch (Throwable $startError) {
                Log::error("Failed to start {$source->name} again after a failed stop for a volume clone: ".$startError->getMessage());
                $message .= " Coolify could not start '{$source->name}' again. Start it manually.";
            }
        }

        $source->team()?->notify(new GeneralNotification(
            $message.' Error: '.remove_iip($e->getMessage()),
            success: false,
        ));
    }

    /**
     * Queues a normal deployment (a rolling update when the application supports it) of the commit
     * of the last successful deployment, so the running containers are replaced only by the same
     * version. Without such a commit, the configured commit is deployed.
     *
     * @return string The sentence for the notification.
     */
    private static function redeployPartlyStoppedApplication(Application $application): string
    {
        $commit = $application->deployment_queue()
            ->where('status', ApplicationDeploymentStatus::FINISHED->value)
            ->where('pull_request_id', 0)
            ->whereNotNull('commit')
            ->where('commit', '!=', 'HEAD')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->value('commit');

        try {
            $result = queue_application_deployment(
                application: $application,
                deployment_uuid: new_public_id(),
                commit: $commit,
                no_questions_asked: true,
            );
            if (($result['status'] ?? null) !== 'queued') {
                throw new RuntimeException($result['message'] ?? 'The deployment was not queued.');
            }
        } catch (Throwable $deployError) {
            Log::error("Failed to queue a redeploy of {$application->name} after a failed stop for a volume clone: ".$deployError->getMessage());

            return "Some containers of '{$application->name}' stopped and others are still running. Coolify could not queue a redeploy of '{$application->name}' to bring back its stopped containers. Redeploy it manually.";
        }

        $sentence = "Some containers of '{$application->name}' stopped and others were still running, so Coolify started a redeploy of '{$application->name}' to bring back its stopped containers";

        return $commit === null
            ? $sentence.'. Coolify could not find the commit that ran before, so the redeploy uses the configured commit or image tag.'
            : $sentence." with the commit that ran before ({$commit}).";
    }

    /**
     * Creates and starts the containers that the stop removed, with the compose file of the last
     * start. Running containers are not recreated, so `up --force-recreate` (StartService) is not
     * used. `docker compose start` is not enough: StopService also removes the stopped containers.
     */
    private static function startStoppedServiceContainers(Service $service): void
    {
        $server = $service->destination->server;
        $workdir = $service->workdir();
        $compose = serverDockerConfigPrefix($server)."docker compose --project-directory {$workdir} -f {$workdir}/docker-compose.yml --project-name {$service->uuid}";

        if (! data_get($service, 'connect_to_docker_network')) {
            instant_remote_process(["{$compose} up -d --no-recreate < /dev/null"], $server);

            return;
        }

        // Like StartService: connect new containers to the predefined network before they start.
        $commands = ["{$compose} up -d --no-recreate --no-start < /dev/null"];
        $composeServices = data_get(parseDockerComposeYaml(data_get($service, 'docker_compose', '')), 'services', []);
        foreach ($composeServices as $serviceName => $serviceConfig) {
            if ($command = StartService::predefinedNetworkConnectCommand($service, $serviceName, $serviceConfig)) {
                $commands[] = $command;
            }
        }
        $commands[] = "{$compose} start < /dev/null";
        instant_remote_process($commands, $server);
    }
}
