<?php

namespace App\Livewire\Project\Shared;

use App\Helpers\SshMultiplexingHelper;
use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\StandaloneSqlite;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;

class GetLogs extends Component
{
    use AuthorizesRequests;

    public const MAX_LOG_LINES = 50000;

    public const MAX_DISPLAY_SIZE_BYTES = 5 * 1024 * 1024;

    public const MAX_DOWNLOAD_SIZE_BYTES = 50 * 1024 * 1024; // 50MB

    /** Docker log timestamp accepted by `--since`, for example 2026-09-28T10:15:30.123456789Z. */
    private const DOCKER_TIMESTAMP_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,9})?Z$/';

    public string $errors = '';

    #[Locked]
    public Application|Service|StandalonePostgresql|StandaloneRedis|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse|StandaloneSqlite|null $resource = null;

    #[Locked]
    public ServiceApplication|ServiceDatabase|null $servicesubtype = null;

    #[Locked]
    public Server $server;

    #[Locked]
    public ?string $container = null;

    /**
     * Containers of one service. Empty keeps the single-container log command.
     *
     * @var list<array{server: string, container: string, label: string}>
     */
    #[Locked]
    public array $sources = [];

    public ?string $displayName = null;

    public ?string $pull_request = null;

    public ?bool $streamLogs = false;

    public ?bool $showTimeStamps = true;

    public ?int $numberOfLines = 100;

    public bool $expandByDefault = false;

    public bool $collapsible = true;

    public bool $fill = false;

    public function mount()
    {
        if (! is_null($this->resource)) {
            if ($this->resource->getMorphClass() === Application::class) {
                $this->showTimeStamps = $this->resource->settings->is_include_timestamps;
            } else {
                if ($this->servicesubtype) {
                    $this->showTimeStamps = $this->servicesubtype->is_include_timestamps;
                } else {
                    $this->showTimeStamps = $this->resource->is_include_timestamps;
                }
            }
            if ($this->resource?->getMorphClass() === Application::class) {
                if (str($this->container)->contains('-pr-')) {
                    $this->pull_request = 'Pull Request: '.str($this->container)->afterLast('-pr-')->beforeLast('_')->value();
                }
            }
        }
    }

    public function instantSave()
    {
        if (! is_null($this->resource)) {
            if (auth()->user()->cannot('update', $this->resource)) {
                return;
            }

            if ($this->resource->getMorphClass() === Application::class) {
                $this->resource->settings->is_include_timestamps = $this->showTimeStamps;
                $this->resource->settings->save();
            }
            if ($this->resource->getMorphClass() === Service::class) {
                $serviceName = str($this->container)->beforeLast('-')->value();
                $subType = $this->resource->applications()->where('name', $serviceName)->first();
                if ($subType) {
                    $subType->is_include_timestamps = $this->showTimeStamps;
                    $subType->save();
                } else {
                    $subType = $this->resource->databases()->where('name', $serviceName)->first();
                    if ($subType) {
                        $subType->is_include_timestamps = $this->showTimeStamps;
                        $subType->save();
                    }
                }
            }
        }
    }

    public function toggleTimestamps()
    {
        $previousValue = $this->showTimeStamps;
        $this->showTimeStamps = ! $this->showTimeStamps;

        try {
            $this->instantSave();
        } catch (\Throwable $e) {
            // Revert the flag to its previous value on failure
            $this->showTimeStamps = $previousValue;

            return handleError($e, $this);
        }
    }

    public function toggleStreamLogs()
    {
        $this->streamLogs = ! $this->streamLogs;
    }

    public function showAllLogs(): string
    {
        $this->numberOfLines = -1;

        return $this->getLogs();
    }

    /**
     * Return timestamped log output for the browser-side log viewer.
     *
     * The output is returned instead of stored in a public property, so it is
     * not included in the component snapshot on every request. When `$since`
     * holds a Docker timestamp, only lines from that moment onward are returned.
     */
    #[Renderless]
    public function getLogs(mixed $since = null): string
    {
        if ($this->sources !== []) {
            return $this->mergedLogs(is_array($since) ? $since : null, self::MAX_DISPLAY_SIZE_BYTES, '5MB', export: false);
        }

        if (! $this->canReadSingleContainer()) {
            return $this->singleContainerDenial();
        }

        $since = is_string($since) ? $since : null;
        if (is_string($since) && preg_match(self::DOCKER_TIMESTAMP_PATTERN, $since)) {
            $options = "--since {$since} -t";
        } else {
            $options = "-n {$this->resolveLogTail()} -t";
        }

        return $this->runLogCommand($this->logCommand($options), self::MAX_DISPLAY_SIZE_BYTES, '5MB');
    }

    public function copyLogs(): string
    {
        if ($this->sources !== []) {
            return sanitizeLogsForExport(
                $this->mergedLogs(null, self::MAX_DISPLAY_SIZE_BYTES, '5MB', export: false, stripTimestamps: ! $this->showTimeStamps),
                $this->logRedactionSecrets(),
            );
        }
        if (! $this->canReadSingleContainer()) {
            return '';
        }

        $options = "-n {$this->resolveLogTail()}".($this->showTimeStamps ? ' -t' : '');

        return sanitizeLogsForExport($this->runLogCommand($this->logCommand($options), self::MAX_DISPLAY_SIZE_BYTES, '5MB', $this->showTimeStamps), $this->logRedactionSecrets());
    }

    public function downloadAllLogs(): string
    {
        if ($this->sources !== []) {
            return sanitizeLogsForExport($this->mergedLogs(null, self::MAX_DOWNLOAD_SIZE_BYTES, '50MB', export: true, all: true), $this->logRedactionSecrets());
        }
        if (! $this->canReadSingleContainer()) {
            return '';
        }

        $command = $this->logCommand($this->showTimeStamps ? '-t' : '');

        return sanitizeLogsForExport($this->runLogCommand($command, self::MAX_DOWNLOAD_SIZE_BYTES, '50MB', $this->showTimeStamps), $this->logRedactionSecrets());
    }

    /**
     * One container keeps the previous denial text. An invalid name is distinct from a missing permission.
     */
    private function singleContainerDenial(): string
    {
        if (! $this->canReadLogs() || ! Server::ownedByCurrentTeam()->where('id', $this->server->id)->exists()) {
            return 'Unauthorized.';
        }
        if (! $this->server->isFunctional() || ! $this->container) {
            return '';
        }
        if (! ValidationPatterns::isValidContainerName($this->container)) {
            return 'Invalid container name.';
        }

        return '';
    }

    private function canReadSingleContainer(): bool
    {
        if (! $this->canReadLogs()) {
            return false;
        }
        if (! Server::ownedByCurrentTeam()->where('id', $this->server->id)->exists()) {
            return false;
        }
        if (! $this->server->isFunctional() || ! $this->container) {
            return false;
        }

        return ValidationPatterns::isValidContainerName($this->container);
    }

    /**
     * Environment values and database credentials of the resource whose logs are exported.
     *
     * @return array{known: array<int, string>, locked: array<int, string>}
     */
    private function logRedactionSecrets(): array
    {
        $resource = $this->resource ?? $this->servicesubtype?->service;

        return EnvironmentVariable::logRedactionSecretsFor($resource, includePreview: $resource instanceof Application && filled($this->pull_request));
    }

    /**
     * Read every container of one service and return one timestamp-ordered stream.
     * A source that is not one of this resource's servers, or whose container name
     * is not safe to pass to the shell, rejects the whole read before any command runs.
     *
     * @param  array<string, mixed>|null  $since
     */
    private function mergedLogs(?array $since, int $maxBytes, string $limitLabel, bool $export, bool $all = false, ?bool $stripTimestamps = null): string
    {
        $sources = $this->authorizedSources();
        if ($sources === null) {
            return $export ? '' : 'Unauthorized.';
        }
        if ($sources === []) {
            return '';
        }

        $tail = $this->resolveLogTail();
        $groups = [];
        $truncated = false;
        foreach ($sources as $source) {
            $sinceValue = $this->dockerSince($since, $source['label']);
            if ($all) {
                $options = '-t';
            } elseif ($sinceValue !== null) {
                $options = "--since {$sinceValue} -t";
            } else {
                $options = "-n {$tail} -t";
            }
            $fetched = $this->executeLogCommand(
                $source['server'],
                $this->logCommand($options, $source['server'], $source['container']),
                $maxBytes,
            );
            $truncated = $truncated || $fetched['truncated'];
            $groups[] = ['label' => $source['label'], 'logs' => $fetched['logs']];
        }

        $stripTimestamps ??= $export && ! $this->showTimeStamps;
        $merged = $this->joinMergedLines($groups, $stripTimestamps, $export);
        if (strlen($merged) > $maxBytes) {
            $merged = rtrim(substr($merged, 0, $maxBytes), "\n");
            $truncated = true;
        }
        if ($truncated && $merged !== '') {
            $merged .= "\n\n[... Output truncated at {$limitLabel} limit ...]";
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>|null  $since
     */
    private function dockerSince(?array $since, string $label): ?string
    {
        $value = $since[$label] ?? null;
        if (! is_string($value) || preg_match(self::DOCKER_TIMESTAMP_PATTERN, $value) !== 1) {
            return null;
        }

        return $value;
    }

    /**
     * @param  list<array{label: string, logs: string}>  $groups
     */
    private function joinMergedLines(array $groups, bool $stripTimestamp, bool $export): string
    {
        $rows = [];
        foreach ($groups as $group) {
            if ($group['logs'] === '') {
                continue;
            }
            foreach (explode("\n", $group['logs']) as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $rows[] = [
                    'timestamp' => explode(' ', $line, 2)[0],
                    'label' => $group['label'],
                    'line' => $line,
                ];
            }
        }

        usort($rows, function (array $left, array $right): int {
            $byTime = $left['timestamp'] <=> $right['timestamp'];

            return $byTime !== 0 ? $byTime : strcmp($left['label'], $right['label']);
        });

        $lines = [];
        foreach ($rows as $row) {
            $text = $stripTimestamp ? $this->stripDockerTimestamp($row['line']) : $row['line'];
            $lines[] = $export ? $row['label'].' | '.$text : $row['label']."\t".$text;
        }

        return implode("\n", $lines);
    }

    private function stripDockerTimestamp(string $line): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?Z (.*)$/s', $line, $matches) === 1) {
            return $matches[1];
        }

        return $line;
    }

    /**
     * @return list<array{server: Server, container: string, label: string}>|null
     */
    private function authorizedSources(): ?array
    {
        if (! $this->canReadLogs() || $this->resource === null) {
            return null;
        }

        $teamId = $this->resourceTeamId();
        if ($teamId === null) {
            return null;
        }

        $allowed = $this->serversForResource()->keyBy(fn (Server $server): string => (string) $server->uuid);
        $resolved = [];
        $labels = [];
        foreach ($this->sources as $source) {
            if (! is_array($source)) {
                return null;
            }
            $uuid = (string) ($source['server'] ?? '');
            $container = (string) ($source['container'] ?? '');
            $label = (string) ($source['label'] ?? '');
            if ($label === '' || str_contains($label, "\t") || str_contains($label, "\n") || isset($labels[$label])) {
                return null;
            }
            $server = $allowed->get($uuid);
            if (! $server instanceof Server || (int) $server->team_id !== $teamId) {
                return null;
            }
            if (! ValidationPatterns::isValidContainerName($container)) {
                return null;
            }
            if (! $server->isFunctional()) {
                continue;
            }
            $labels[$label] = true;
            $resolved[] = [
                'server' => $server,
                'container' => $container,
                'label' => $label,
            ];
        }

        return $resolved;
    }

    private function resourceTeamId(): ?int
    {
        if (! is_object($this->resource) || ! method_exists($this->resource, 'team')) {
            return null;
        }
        $team = $this->resource->team();
        $id = is_object($team) ? ($team->id ?? null) : null;

        return is_numeric($id) ? (int) $id : null;
    }

    private function serversForResource(): Collection
    {
        $resource = $this->resource;
        if ($resource instanceof Application) {
            return $resource->maintenanceServers();
        }
        if ($resource instanceof Service) {
            return collect([$resource->server])->filter();
        }
        if (is_object($resource) && method_exists($resource, 'destination')) {
            return collect([$resource->destination?->server])->filter();
        }

        return collect();
    }

    private function resolveLogTail(): int|string
    {
        if ($this->numberOfLines === -1) {
            return 'all';
        }
        if (is_null($this->numberOfLines) || $this->numberOfLines <= 0) {
            $this->numberOfLines = 1000;
        }
        if ($this->numberOfLines > self::MAX_LOG_LINES) {
            $this->numberOfLines = self::MAX_LOG_LINES;
        }

        return $this->numberOfLines;
    }

    private function logCommand(string $options, ?Server $server = null, ?string $container = null): string
    {
        $server ??= $this->server;
        $container ??= (string) $this->container;
        $options = trim($options);
        $options = $options === '' ? '' : "{$options} ";
        $command = $server->isSwarm()
            ? "docker service logs {$options}{$container}"
            : "docker logs {$options}{$container}";

        if ($server->isNonRoot()) {
            $command = parseCommandsByLineForSudo(collect($command), $server)[0];
        }

        return $command;
    }

    private function runLogCommand(string $command, int $maxBytes, string $limitLabel, bool $timestamped = true): string
    {
        $fetched = $this->executeLogCommand($this->server, $command, $maxBytes);
        $logs = $fetched['logs'];
        if ($timestamped) {
            // Docker writes stdout and stderr separately, so sort timestamped lines back into order.
            $logs = str($logs)->split('/\n/')->sort(function ($a, $b) {
                return explode(' ', $a)[0] <=> explode(' ', $b)[0];
            })->join("\n");
        }

        if ($fetched['truncated']) {
            $logs .= "\n\n[... Output truncated at {$limitLabel} limit ...]";
        }

        return $logs;
    }

    /**
     * @return array{logs: string, truncated: bool}
     */
    private function executeLogCommand(Server $server, string $command, int $maxBytes): array
    {
        $sshCommand = SshMultiplexingHelper::generateSshCommand($server, $this->boundedLogCommand($command, $maxBytes));

        // boundedLogCommand() caps the output at $maxBytes + 1, so one extra byte means truncation.
        $output = Process::timeout(config('constants.ssh.command_timeout'))->run($sshCommand)->output();
        $truncated = strlen($output) > $maxBytes;
        $logs = rtrim(removeAnsiColors($truncated ? substr($output, 0, $maxBytes) : $output), "\n");

        return ['logs' => $logs, 'truncated' => $truncated];
    }

    private function boundedLogCommand(string $command, int $maxBytes): string
    {
        return "({$command}) 2>&1 | head -c ".($maxBytes + 1);
    }

    /**
     * Container output can contain secrets, so only users who can edit the resource (or the server,
     * for server-level containers) may read it.
     */
    private function canReadLogs(): bool
    {
        return auth()->user()?->can('update', $this->resource ?? $this->server) ?? false;
    }

    public function render()
    {
        return view('livewire.project.shared.get-logs', [
            'canReadLogs' => $this->canReadLogs(),
        ]);
    }
}
