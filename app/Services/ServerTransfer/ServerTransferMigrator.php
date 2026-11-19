<?php

namespace App\Services\ServerTransfer;

use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\MigrateServerToInstanceJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\Project;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledTaskExecution;
use App\Models\ScheduledVolumeBackupExecution;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Rules\SafeWebhookUrl;
use App\Services\Security\SensitiveDataRedactor;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ServerTransferMigrator
{
    /** Time the target gets for one import request. Its nginx can stop waiting earlier (99 s). */
    public const IMPORT_TIMEOUT_SECONDS = 300;

    /** How often and how long to ask the target for the result after the import request failed. */
    public const STATUS_PROBE_ATTEMPTS = 20;

    public const STATUS_PROBE_INTERVAL_SECONDS = 15;

    public const STATUS_REQUEST_TIMEOUT_SECONDS = 15;

    public const CHECK_TIMEOUT_SECONDS = 15;

    /**
     * Time the queued job gets. It must be longer than the import request and all status probes together,
     * plus time for the export, so the worker does not stop the job after the target took the server.
     */
    public const MIGRATION_TIMEOUT_SECONDS = 1500;

    /** Lifetime of the token that a transfer back gives the other instance: the job timeout plus time in the queue. */
    public const TRANSFER_BACK_TOKEN_SECONDS = 3600;

    /** Longer than the job timeout, so a running state older than this one is stale. */
    public const STALE_MIGRATION_SECONDS = 1800;

    public function __construct(
        private ServerTransferExporter $exporter,
        private ServerTransferClaimer $claimer,
    ) {}

    /** The UI shows this many of the latest log lines. */
    public const MAX_LOG_ENTRIES = 100;

    /**
     * System-wide git sources that the target of the last precheck does not have.
     *
     * @var array{github: list<string>, gitlab: list<string>}
     */
    private array $missingSystemSources = ['github' => [], 'gitlab' => []];

    /**
     * Run the prechecks now, so the user sees problems at once, then run the transfer in a queued job.
     * Every check writes a line to the transfer log; a failed check stops the transfer before it starts.
     */
    public function start(Server $server, string $targetUrl, string $targetToken): void
    {
        $targetUrl = $this->normalizeTargetUrl($targetUrl);
        $token = $this->normalizeToken($targetToken);
        if ($this->hasRunningMigration($server->fresh())) {
            throw new RuntimeException('A transfer of this server is already running.');
        }

        $startedAt = now()->toIso8601String();
        $log = [$this->logEntry("Running checks before the transfer to {$targetUrl}.")];
        $checks = [
            ...$this->migrationChecks($server),
            $this->sshCheck($server),
            ...$this->targetChecks($server, $targetUrl, $token),
        ];
        foreach ($checks as $check) {
            $log[] = match ($check['ok']) {
                true => ['at' => $check['at'], 'level' => 'success', 'message' => "OK: {$check['label']}."],
                false => ['at' => $check['at'], 'level' => 'error', 'message' => "FAILED: {$check['label']}. {$check['message']}"],
                null => ['at' => $check['at'], 'level' => 'warning', 'message' => "NOT CHECKED: {$check['label']}. {$check['message']}"],
            };
        }
        foreach ([...$this->sharedProjectNotes($server), ...$this->systemSourceNotes($server)] as $note) {
            $log[] = $this->logEntry($note);
        }

        $failed = array_values(array_filter($checks, fn (array $check) => $check['ok'] === false));
        if ($failed !== []) {
            $error = count($failed).' of '.count($checks).' checks failed: '.implode(' ', array_column($failed, 'message'));
            $log[] = $this->logEntry('The transfer did not start, because '.count($failed).' check(s) failed. Fix them, then transfer again.', 'error');
            DB::transaction(function () use ($server, $targetUrl, $startedAt, $error, $log) {
                $this->writeMigration(Server::query()->lockForUpdate()->findOrFail($server->id), [
                    'status' => 'failed',
                    'phase' => 'checks',
                    'target_url' => $targetUrl,
                    'started_at' => $startedAt,
                    'finished_at' => now()->toIso8601String(),
                    'error' => $error,
                    'log' => $log,
                ]);
            });

            throw new RuntimeException($error);
        }

        $log[] = $this->logEntry('All checks passed. The transfer is queued.');
        DB::transaction(function () use ($server, $targetUrl, $startedAt, $log) {
            $locked = Server::query()->lockForUpdate()->findOrFail($server->id);
            if ($this->hasRunningMigration($locked)) {
                throw new RuntimeException('A transfer of this server is already running.');
            }
            $this->writeMigration($locked, [
                'status' => 'running',
                'target_url' => $targetUrl,
                'started_at' => $startedAt,
                'log' => $log,
            ]);
        });

        MigrateServerToInstanceJob::dispatch($server, $targetUrl, $token);
    }

    /**
     * Save the state of the queued transfer on the source server, so the UI and the API can show it.
     *
     * @param  array<string, mixed>  $details
     */
    public function recordMigration(Server $server, string $status, array $details = []): void
    {
        $entry = match ($status) {
            'succeeded' => $this->logEntry('Transfer finished. The target instance manages the server now.', 'success'),
            'unknown' => $this->logEntry('Transfer result unknown: '.data_get($details, 'error', 'unknown error'), 'warning'),
            default => $this->logEntry('Transfer failed: '.data_get($details, 'error', 'unknown error'), 'error'),
        };

        DB::transaction(function () use ($server, $status, $details, $entry) {
            $locked = Server::query()->lockForUpdate()->find($server->id);
            if (! $locked) {
                return;
            }
            $migration = array_merge((array) data_get($locked->server_metadata, 'migration', []), $details, [
                'status' => $status,
                'finished_at' => now()->toIso8601String(),
            ]);
            $migration['log'] = $this->appendToLog((array) data_get($migration, 'log', []), $entry);
            $this->writeMigration($locked, $migration);
        });
    }

    /**
     * Record the result of a transfer job that the worker stopped, for example at its timeout. When the export
     * was already sent, ask the target once, because the target can manage the server already.
     */
    public function recordStoppedMigration(Server $server, string $targetUrl, string $targetToken, string $error): void
    {
        $exportId = data_get($server->fresh()?->server_metadata, 'migration.export_id');
        if (! is_string($exportId)) {
            $this->recordMigration($server, 'failed', ['error' => $error]);

            return;
        }

        $targetUrl = $this->normalizeTargetUrl($targetUrl);
        $status = $this->targetTransferStatus($targetUrl, $this->normalizeToken($targetToken), $server->uuid, $exportId);

        if ($status === 'claimed') {
            try {
                $this->claimer->markTransferred($server, exportId: $exportId, targetInstanceUrl: $targetUrl);
                $this->recordMigration($server, 'succeeded', [
                    'warnings' => ['The transfer job stopped before it finished. The target status confirms that the target manages the server.'],
                ]);
            } catch (Throwable) {
                $this->recordMigration($server, 'unknown', [
                    'error' => "{$error} The target manages the server, but this instance could not mark it as transferred. Call POST /api/v1/servers/{$server->uuid}/transfer/complete on this instance.",
                ]);
            }

            return;
        }

        // The target answered that it does not manage this export, so this instance still manages the server.
        if (in_array($status, ['imported', 'absent'], true)) {
            $this->recordMigration($server, 'failed', ['error' => $error]);

            return;
        }

        $this->recordMigration($server, 'unknown', [
            'error' => "{$error} This instance could not ask {$targetUrl} whether it imported the server. ".
                "Check the server on the target. If the target manages it, call POST /api/v1/servers/{$server->uuid}/transfer/complete on this instance. ".
                'If not, transfer again.',
        ]);
    }

    /**
     * Add one line to the transfer log, so the UI can show where the transfer is.
     *
     * @param  array<string, mixed>  $fields  values to save in the transfer state with the line
     */
    public function log(Server $server, string $message, string $level = 'info', array $fields = []): void
    {
        $entry = $this->logEntry($message, $level);

        DB::transaction(function () use ($server, $entry, $fields) {
            $locked = Server::query()->lockForUpdate()->find($server->id);
            if (! $locked) {
                return;
            }
            $migration = array_merge((array) data_get($locked->server_metadata, 'migration', []), $fields);
            $migration['log'] = $this->appendToLog((array) data_get($migration, 'log', []), $entry);
            $this->writeMigration($locked, $migration);
        });
    }

    /**
     * @return array{at: string, level: string, message: string}
     */
    private function logEntry(string $message, string $level = 'info'): array
    {
        return ['at' => now()->toIso8601String(), 'level' => $level, 'message' => $message];
    }

    /**
     * @param  list<array<string, string>>  $log
     * @param  array<string, string>  $entry
     * @return list<array<string, string>>
     */
    private function appendToLog(array $log, array $entry): array
    {
        return array_slice([...$log, $entry], -self::MAX_LOG_ENTRIES);
    }

    /**
     * @return array{label: string, ok: bool|null, message: string|null, at: string}
     */
    private function check(string $label, ?bool $ok, ?string $message = null): array
    {
        return ['label' => $label, 'ok' => $ok, 'message' => $message, 'at' => now()->toIso8601String()];
    }

    /**
     * @return array{label: string, ok: bool|null, message: string|null, at: string}
     */
    private function sshCheck(Server $server): array
    {
        ['uptime' => $reachable, 'error' => $error] = $server->validateConnection();

        return $this->check(
            'this instance can connect to the server over SSH',
            (bool) $reachable,
            $reachable ? null : 'This instance cannot connect to the server over SSH: '.rtrim($error ?: 'unknown error', '. ').'. Fix the connection, then transfer again.',
        );
    }

    /**
     * A running state older than the job can run is left over from a stopped worker, so it does not block a new start.
     */
    private function hasRunningMigration(Server $server): bool
    {
        $startedAt = data_get($server->server_metadata, 'migration.started_at');

        return data_get($server->server_metadata, 'migration.status') === 'running'
            && is_string($startedAt)
            && Carbon::parse($startedAt)->gt(now()->subSeconds(self::STALE_MIGRATION_SECONDS));
    }

    /**
     * @param  array<string, mixed>  $migration
     */
    private function writeMigration(Server $server, array $migration): void
    {
        $metadata = $server->server_metadata ?? [];
        $metadata['migration'] = $migration;
        $server->server_metadata = $metadata;
        $server->save();
    }

    /**
     * @return list<array{label: string, ok: bool|null, message: string|null, at: string}>
     */
    private function migrationChecks(Server $server): array
    {
        $activeDeployments = ApplicationDeploymentQueue::query()
            ->where('server_id', $server->id)
            ->whereIn('status', [ApplicationDeploymentStatus::QUEUED->value, ApplicationDeploymentStatus::IN_PROGRESS->value])
            ->count();

        return [
            $this->check('the server is not the Coolify host', $server->id !== 0,
                'The Coolify host (localhost) cannot be transferred between instances.'),
            $this->check('this instance manages the server', ! $server->isTransferredAway(),
                'This server was already transferred to another instance.'),
            $this->check('no deployment is queued or running on the server', $activeDeployments === 0,
                "{$activeDeployments} deployment(s) on this server are queued or running. Wait until they finish or cancel them, then transfer the server."),
            ...array_map(fn (array $job) => $this->check("no {$job['label']} is running on the server", $job['count'] === 0,
                "{$job['count']} {$job['label']}(s) on this server are running. Wait until they finish, then transfer the server."), $this->runningJobs($server)),
        ];
    }

    /**
     * Backups and scheduled tasks that run on the server. A transfer during a run leaves the run without an owner.
     * A run that has been 'running' for a day is left over from a stopped worker, so it does not block.
     *
     * @return list<array{label: string, count: int}>
     */
    private function runningJobs(Server $server): array
    {
        $onServer = fn ($schedule) => $schedule && (int) $schedule->server()?->id === (int) $server->id;
        $running = fn (string $model, string $schedule) => $model::query()
            ->where('status', 'running')
            ->where('created_at', '>', now()->subDay())
            ->with($schedule)
            ->get()
            ->filter(fn (Model $execution) => $onServer($execution->{$schedule}))
            ->count();

        return [
            ['label' => 'database backup', 'count' => $running(ScheduledDatabaseBackupExecution::class, 'scheduledDatabaseBackup')],
            ['label' => 'volume backup', 'count' => $running(ScheduledVolumeBackupExecution::class, 'scheduledVolumeBackup')],
            ['label' => 'scheduled task', 'count' => $running(ScheduledTaskExecution::class, 'scheduledTask')],
        ];
    }

    /**
     * System-wide git sources are not transferred: an application re-links only to one with the same uuid on the
     * target. One note per source that the target does not have, with the applications that lose their source.
     *
     * @return list<string>
     */
    private function systemSourceNotes(Server $server): array
    {
        $notes = [];
        foreach ($this->systemSources($server) as $kind => $sources) {
            foreach ($sources as $uuid => $source) {
                if (in_array($uuid, $this->missingSystemSources[$kind], true)) {
                    $label = $kind === 'github' ? 'GitHub App' : 'GitLab App';
                    $notes[] = "NOTE: the system-wide {$label} '{$source['name']}' of this instance is not transferred, and the target has no matching one. "
                        .'These applications need a new git source on the target: '.implode(', ', $source['applications']).'. The finish list on the target shows them.';
                }
            }
        }

        return $notes;
    }

    /**
     * @return array{github: array<string, array{name: string, applications: list<string>}>, gitlab: array<string, array{name: string, applications: list<string>}>}
     */
    private function systemSources(Server $server): array
    {
        $sources = ['github' => [], 'gitlab' => []];
        foreach ($server->applications()->unique('id') as $application) {
            $source = $application->source;
            $kind = match (true) {
                $source instanceof GithubApp => 'github',
                $source instanceof GitlabApp => 'gitlab',
                default => null,
            };
            if ($kind === null || ! $source->is_system_wide) {
                continue;
            }
            $sources[$kind][$source->uuid]['name'] = $source->name;
            $sources[$kind][$source->uuid]['applications'][] = $application->name;
        }

        return $sources;
    }

    /**
     * One note for every project that has resources on this server and on other servers. Only the
     * resources of this server are transferred, so the user must know that the project is split.
     *
     * @return list<string>
     */
    private function sharedProjectNotes(Server $server): array
    {
        $resourcesOnServer = collect([
            ...$server->applications(),
            ...$server->databases(),
            ...$server->services()->get(),
        ]);
        $keysOnServer = $resourcesOnServer->map(fn (Model $resource) => $resource::class.':'.$resource->getKey())->flip();

        $projectIds = Environment::query()
            ->whereIn('id', $resourcesOnServer->pluck('environment_id')->filter()->unique())
            ->distinct()
            ->pluck('project_id');
        $projectIdByEnvironment = Environment::query()->whereIn('project_id', $projectIds)->pluck('project_id', 'id');

        $otherServerCounts = collect([Application::class, Service::class, ...array_values(STANDALONE_DATABASE_MODELS)])
            ->flatMap(fn (string $model) => $model::query()
                ->whereIn('environment_id', $projectIdByEnvironment->keys())
                ->pluck('environment_id', 'id')
                ->reject(fn (int|string $environmentId, int|string $id) => $keysOnServer->has("{$model}:{$id}")))
            ->countBy(fn (int|string $environmentId) => $projectIdByEnvironment[$environmentId]);

        return Project::query()
            ->whereIn('id', $otherServerCounts->keys())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Project $project) => "NOTE: project '{$project->name}' also has {$otherServerCounts[$project->id]} ".
                Str::plural('resource', $otherServerCounts[$project->id]).' on other servers. They stay on this instance; only the resources of this server are transferred.')
            ->all();
    }

    private function assertCanMigrate(Server $server): void
    {
        foreach ($this->migrationChecks($server) as $check) {
            if (! $check['ok']) {
                throw new RuntimeException($check['message']);
            }
        }
    }

    /**
     * One-shot migrate: export on this instance → import+claim on target → complete on this instance.
     *
     * @return array{
     *     server_uuid: string,
     *     export_id: string|null,
     *     target_url: string,
     *     import: array<string, mixed>,
     *     complete: array<string, mixed>,
     *     warnings: list<string>,
     *     checklist: list<string>,
     *     message: string
     * }
     */
    public function migrate(Server $server, string $targetUrl, string $targetToken): array
    {
        $server->refresh();
        $this->assertCanMigrate($server);

        $targetUrl = $this->normalizeTargetUrl($targetUrl);
        $token = $this->normalizeToken($targetToken);

        $this->log($server, 'Exporting the server configuration and its resources.');
        $bundle = $this->exporter->export($server, includeSensitive: true);
        $exportId = data_get($bundle, 'export_id');
        $warnings = array_values((array) data_get($bundle, 'warnings', []));
        $checklist = $this->postTransferChecklist($bundle, $targetUrl);
        // A job that the worker stops after this line reads the export ID to ask the target for the result.
        $this->log($server, "Export {$exportId} created. Sending it to {$targetUrl}. The target imports the server and takes over management.", fields: ['export_id' => $exportId]);

        // Target import+claim runs in its own DB transaction on the remote instance.
        // Source DB is unchanged until markTransferred below — so a failed remote import leaves source intact.
        $importBody = $this->postImportToTarget(
            server: $server,
            targetUrl: $targetUrl,
            token: $token,
            bundle: $bundle,
        );

        // The target fills in its own public URL; the URL typed here can be an internal address.
        $targetChecklist = array_values(array_filter((array) data_get($importBody, 'checklist', []), 'is_string'));
        if ($targetChecklist !== []) {
            $checklist = $targetChecklist;
        }

        if (is_array(data_get($importBody, 'warnings'))) {
            $warnings = array_values(array_unique(array_merge($warnings, $importBody['warnings'])));
        }

        // Keep managing the server here until the target really manages it, so it is never left unmanaged.
        if (data_get($importBody, 'claimed') !== true) {
            throw new RuntimeException(
                "Server was imported on {$targetUrl}, but the target could not take management of it. ".
                'This instance still manages the server. Delete the imported server on the target, fix the problem, then transfer again. '.
                'Target warnings: '.(implode(' ', (array) data_get($importBody, 'warnings', [])) ?: 'none')
            );
        }

        $this->log($server, 'The target imported the server and manages it now. Disabling management on this instance.', 'success');

        // Cross-instance 2PC is impossible: if complete fails after a successful import, the target
        // already owns the server. Surface that clearly so the operator can complete the transfer here.
        try {
            $complete = $this->claimer->markTransferred(
                $server,
                exportId: is_string($exportId) ? $exportId : null,
                targetInstanceUrl: $targetUrl,
            );
        } catch (Throwable $e) {
            // Query errors include SQL and bindings, so they are never shown to the user.
            $reason = $e instanceof QueryException ? 'a database error occurred' : remove_iip($e->getMessage());
            throw new RuntimeException(
                "Server was imported on {$targetUrl}, but this instance could not mark it as transferred: {$reason}. ".
                'Call POST /api/v1/servers/{uuid}/transfer/complete on this instance so automations stay disabled here. Do not re-import on the target.',
                previous: $e
            );
        }

        return [
            'server_uuid' => $server->uuid,
            'export_id' => is_string($exportId) ? $exportId : null,
            'target_url' => $targetUrl,
            'import' => app(SensitiveDataRedactor::class)->redactValue($importBody),
            'complete' => $complete,
            'warnings' => $warnings,
            'checklist' => $checklist,
            // The target sends this URL, and the UI renders it as a link.
            'target_server_url' => self::isLinkableUrl(data_get($importBody, 'server_url')) ? data_get($importBody, 'server_url') : null,
            'message' => 'Server migrated to '.$targetUrl.'. Automations disabled on this instance.',
        ];
    }

    /**
     * Manual steps the user must do after the transfer. The exporter writes {target_url} where the URL of the target belongs.
     *
     * @param  array<string, mixed>  $bundle
     * @return list<string>
     */
    private function postTransferChecklist(array $bundle, string $targetUrl): array
    {
        $steps = array_filter((array) data_get($bundle, 'post_transfer_checklist', []), 'is_string');

        return array_values(array_unique(array_map(
            fn (string $step) => str_replace('{target_url}', $targetUrl, $step),
            $steps,
        )));
    }

    /**
     * Undo a transfer: ask the instance that manages the server now to transfer it back to this instance. That
     * instance runs its prechecks at once and then a normal transfer; the import here replaces the archive.
     * This instance creates a short-lived token for the import, scoped to the team of the archive.
     */
    public function requestTransferBack(Server $archive, User $user, string $managerUrl, string $managerToken, string $thisInstanceUrl): void
    {
        if (! $archive->isTransferredAway()) {
            throw new RuntimeException('Only a server that was transferred to another instance can be transferred back.');
        }

        $managerUrl = $this->normalizeTargetUrl($managerUrl);
        $managerToken = $this->normalizeToken($managerToken);
        $thisInstanceUrl = rtrim(trim($thisInstanceUrl), '/');
        if (! filter_var($thisInstanceUrl, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Enter the URL at which the other instance reaches this instance.');
        }

        // The import checks, the import, and the status probes need only write and deploy. The claim after the
        // import deletes the token, and the expiry covers a failed import: the transfer job runs at most 25 minutes.
        $importToken = $user->createToken(self::transferBackTokenName($archive->uuid), ['write', 'deploy'], now()->addSeconds(self::TRANSFER_BACK_TOKEN_SECONDS), $archive->team_id);
        $migrateUrl = $managerUrl.'/api/v1/servers/'.rawurlencode($archive->uuid).'/migrate';

        try {
            $response = $this->targetRequest($migrateUrl, $managerToken, self::CHECK_TIMEOUT_SECONDS * 4)
                ->post($migrateUrl, ['target_url' => $thisInstanceUrl, 'target_token' => $importToken->plainTextToken]);
        } catch (ConnectionException $e) {
            $importToken->accessToken->delete();
            throw new RuntimeException('This instance cannot reach '.remove_iip($managerUrl).': '.remove_iip($e->getMessage()), previous: $e);
        }

        if ($response->status() !== 202) {
            $importToken->accessToken->delete();
            $message = remove_iip((string) (data_get($response->json(), 'message') ?: 'no details'));
            throw new RuntimeException(match ($response->status()) {
                401 => 'The other instance does not accept the API token.',
                403 => 'The API token of the other instance needs root, or read:sensitive and write, from an admin or owner. '.$message,
                404 => 'The other instance does not know this server, or does not support transfers. '.$message,
                default => "The other instance did not start the transfer back (HTTP {$response->status()}): {$message}",
            });
        }

        DB::transaction(function () use ($archive, $managerUrl) {
            $locked = Server::query()->lockForUpdate()->findOrFail($archive->id);
            $metadata = $locked->server_metadata ?? [];
            $metadata['transfer']['transfer_back'] = ['requested_at' => now()->toIso8601String(), 'from' => $managerUrl];
            $locked->server_metadata = $metadata;
            $locked->save();
        });
    }

    /**
     * The rules for the URL of another Coolify instance that this instance sends credentials to: https and a public
     * address, or a local address in development. Every stored or prefilled instance URL must pass them.
     *
     * @throws RuntimeException|ValidationException
     */
    public static function validateInstanceUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('A valid target instance URL is required (e.g. https://coolify.example.com).');
        }

        if (self::isLocalDevelopmentTarget($url)) {
            return $url;
        }

        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw new RuntimeException('The target instance URL must use https, because the transfer sends credentials.');
        }

        Validator::make(['target_url' => $url], [
            'target_url' => ['required', new SafeWebhookUrl],
        ])->validate();

        return $url;
    }

    public static function isValidInstanceUrl(?string $url): bool
    {
        try {
            self::validateInstanceUrl((string) $url);

            return true;
        } catch (RuntimeException|ValidationException) {
            return false;
        }
    }

    /**
     * A URL from another instance that the UI may render as a link: https, or http in development.
     */
    public static function isLinkableUrl(mixed $url): bool
    {
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return $scheme === 'https' || ($scheme === 'http' && isDev());
    }

    public static function transferBackTokenName(string $serverUuid): string
    {
        return "transfer back {$serverUuid}";
    }

    private function normalizeTargetUrl(string $targetUrl): string
    {
        $targetUrl = self::validateInstanceUrl($targetUrl);

        if (self::isLocalDevelopmentTarget($targetUrl) && (file_exists('/.dockerenv') || is_file('/run/.containerenv'))) {
            return (string) preg_replace(
                '#^(https?://)(localhost|127\.0\.0\.1)(?=[:/]|$)#i',
                '$1host.docker.internal',
                $targetUrl,
            );
        }

        return $targetUrl;
    }

    private static function isLocalDevelopmentTarget(string $url): bool
    {
        return isDev()
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            && in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), ['localhost', '127.0.0.1', 'host.docker.internal'], true);
    }

    private function normalizeToken(string $targetToken): string
    {
        $token = trim($targetToken);
        if (str_starts_with(strtolower($token), 'bearer ')) {
            $token = trim(substr($token, 7));
        }
        if ($token === '') {
            throw new RuntimeException('A target instance API token is required (root or write + deploy).');
        }

        return $token;
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return array<string, mixed>
     */
    private function postImportToTarget(Server $server, string $targetUrl, string $token, array $bundle): array
    {
        $importUrl = $targetUrl.'/api/v1/servers/import';

        try {
            $response = $this->targetRequest($importUrl, $token, self::IMPORT_TIMEOUT_SECONDS)
                ->post($importUrl, [
                    'bundle' => $bundle,
                    'dry_run' => false,
                    'preserve_uuids' => true,
                    'adopt_mode' => true,
                ]);
        } catch (ConnectionException $e) {
            return $this->resultFromTargetStatus($server, $targetUrl, $token, $bundle, 'the request could not reach the target or timed out')
                ?? throw new RuntimeException('Could not reach target instance at '.remove_iip($importUrl).': '.remove_iip($e->getMessage()), previous: $e);
        } catch (Throwable $e) {
            throw new RuntimeException('Transfer to target failed: '.remove_iip($e->getMessage()), previous: $e);
        }

        $importBody = $response->json();
        if (! is_array($importBody)) {
            $importBody = ['raw' => remove_iip($response->body())];
        }

        if ($response->status() === 404) {
            throw new RuntimeException('The target instance does not support server transfer. Upgrade it to Coolify '.config('constants.coolify.version').' or newer.');
        }

        // A proxy timeout (502/504) or a server error does not mean that the import failed: the target can still finish it.
        if ($response->serverError()) {
            $result = $this->resultFromTargetStatus($server, $targetUrl, $token, $bundle, "the target answered with HTTP {$response->status()}");
            if ($result !== null) {
                return $result;
            }
        }

        if (! $response->successful()) {
            $message = data_get($importBody, 'message')
                ?? data_get($importBody, 'errors')
                ?? $response->body();
            if (is_array($message)) {
                $message = json_encode($message);
            }
            throw new RuntimeException("Target import failed (HTTP {$response->status()}): ".remove_iip((string) $message));
        }

        return $importBody;
    }

    /**
     * Ask the target whether it accepts the token and can import this server, so a wrong token or a
     * conflict on the target fails before the transfer starts instead of in the queued job. When a
     * check fails that the target checks depend on, those checks are reported as not checked.
     *
     * @return list<array{label: string, ok: bool|null, message: string|null, at: string}>
     */
    private function targetChecks(Server $server, string $targetUrl, string $token): array
    {
        $checkUrl = $targetUrl.'/api/v1/servers/import/check';
        $reachLabel = 'the target instance at '.remove_iip($targetUrl).' can be reached';
        $notChecked = fn (string $label) => $this->check('the checks on the target instance', null, "They did not run, because \"{$label}\" failed.");

        $systemSources = $this->systemSources($server);
        $this->missingSystemSources = ['github' => [], 'gitlab' => []];
        try {
            $response = $this->targetRequest($checkUrl, $token, self::CHECK_TIMEOUT_SECONDS)
                ->get($checkUrl, [
                    'server_uuid' => $server->uuid,
                    'ip' => $server->ip,
                    'port' => $server->port,
                    'schema_version' => ServerTransferBundle::SCHEMA_VERSION,
                    'system_github_apps' => array_keys($systemSources['github']),
                    'system_gitlab_apps' => array_keys($systemSources['gitlab']),
                ]);
        } catch (ConnectionException $e) {
            return [
                $this->check($reachLabel, false, 'This instance cannot reach the target instance: '.remove_iip($e->getMessage())),
                $notChecked($reachLabel),
            ];
        }

        $checks = [$this->check($reachLabel, true)];
        $status = $response->status();
        $message = remove_iip((string) (data_get($response->json(), 'message') ?: 'no details'));

        // An unknown route answers 404 before the token is checked, so this check comes first.
        $gates = [
            [404, 'the target instance supports server transfer', 'The target instance does not support server transfer. Upgrade it to Coolify '.config('constants.coolify.version').' or newer.'],
            [401, 'the target instance accepts the API token', 'The target instance does not accept the API token. Create a new token on the target instance and paste it here.'],
            [403, 'the API token can create servers on the target instance', 'Use a token with the root permission, or the write and deploy permissions, from an admin or owner. Target response: '.$message],
        ];
        foreach ($gates as [$failStatus, $label, $failMessage]) {
            if ($status === $failStatus) {
                return [...$checks, $this->check($label, false, $failMessage), $notChecked($label)];
            }
            $checks[] = $this->check($label, true);
        }

        foreach (['github', 'gitlab'] as $kind) {
            $this->missingSystemSources[$kind] = array_values(array_filter((array) data_get($response->json(), "missing_system_sources.{$kind}", []), 'is_string'));
        }

        $remoteChecks = data_get($response->json(), 'checks');
        if (! in_array($status, [200, 422], true) || ! is_array($remoteChecks)) {
            return [...$checks, $this->check('the target instance answered the checks', false, "The target instance answered with HTTP {$status}: {$message}")];
        }

        foreach ($remoteChecks as $remoteCheck) {
            $checks[] = $this->check(
                'on the target: '.data_get($remoteCheck, 'label', 'unknown check'),
                (bool) data_get($remoteCheck, 'ok'),
                is_string(data_get($remoteCheck, 'message')) ? remove_iip(data_get($remoteCheck, 'message')) : null,
            );
        }

        return $checks;
    }

    /**
     * Ask the target whether it imported this export. Returns null when the target does not know it.
     *
     * @param  array<string, mixed>  $bundle
     * @return array<string, mixed>|null
     */
    private function resultFromTargetStatus(Server $server, string $targetUrl, string $token, array $bundle, string $reason): ?array
    {
        $serverUuid = (string) data_get($bundle, 'server.uuid');
        $exportId = (string) data_get($bundle, 'export_id');

        $this->log($server, "The import request did not finish ({$reason}). The target can still finish the import, so this instance asks the target for the result.", 'warning');

        for ($attempt = 1; $attempt <= self::STATUS_PROBE_ATTEMPTS; $attempt++) {
            Sleep::for(self::STATUS_PROBE_INTERVAL_SECONDS)->seconds();
            $this->log($server, "Asking the target for the import result (attempt {$attempt} of ".self::STATUS_PROBE_ATTEMPTS.').');

            $status = $this->targetTransferStatus($targetUrl, $token, $serverUuid, $exportId);

            // A rejected token stays rejected, so more attempts cannot confirm the import.
            if ($status === 'rejected') {
                return null;
            }

            if (in_array($status, ['claimed', 'imported'], true)) {
                return [
                    'server_uuid' => $serverUuid,
                    'export_id' => $exportId,
                    'claimed' => $status === 'claimed',
                    'warnings' => ['The import request to the target timed out. The target status confirms the import.'],
                ];
            }
        }

        return null;
    }

    /**
     * Ask the target once what it knows about this export: 'claimed' or 'imported', 'absent' when the target
     * does not have it, 'rejected' when the target rejects the token, or 'unknown' when there is no clear answer.
     *
     * @return 'claimed'|'imported'|'absent'|'rejected'|'unknown'
     */
    private function targetTransferStatus(string $targetUrl, string $token, string $serverUuid, string $exportId): string
    {
        $statusUrl = $targetUrl.'/api/v1/servers/'.rawurlencode($serverUuid).'/transfer';

        try {
            $response = $this->targetRequest($statusUrl, $token, self::STATUS_REQUEST_TIMEOUT_SECONDS)->get($statusUrl);
        } catch (Throwable) {
            return 'unknown';
        }

        if (in_array($response->status(), [401, 403], true)) {
            return 'rejected';
        }
        if ($response->status() === 404) {
            return 'absent';
        }
        if (! $response->successful()) {
            return 'unknown';
        }
        if (data_get($response->json(), 'transfer.export_id') !== $exportId) {
            return 'absent';
        }

        $status = data_get($response->json(), 'transfer.status');

        return in_array($status, ['claimed', 'imported'], true) ? $status : 'unknown';
    }

    private function targetRequest(string $url, string $token, int $timeout): PendingRequest
    {
        return Http::timeout($timeout)
            ->withOptions(self::isLocalDevelopmentTarget($url)
                ? ['allow_redirects' => false]
                : SafeWebhookUrl::httpClientOptions($url))
            ->acceptJson()
            ->withToken($token)
            ->asJson();
    }
}
