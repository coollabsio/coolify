<?php

namespace App\Livewire\Project\Shared;

use App\Actions\Sentinel\FetchContainerLogs;
use App\Helpers\SshMultiplexingHelper;
use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\Node;
use App\Models\NodeWorkload;
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
    public Application|Service|StandalonePostgresql|StandaloneRedis|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse|StandaloneSqlite|NodeWorkload|null $resource = null;

    #[Locked]
    public ServiceApplication|ServiceDatabase|null $servicesubtype = null;

    /** A cluster application reads its logs from a Node through Sentinel instead of SSH. */
    #[Locked]
    public Server|Node $server;

    #[Locked]
    public ?string $container = null;

    public ?string $displayName = null;

    public ?string $pull_request = null;

    public ?bool $streamLogs = false;

    public ?bool $showTimeStamps = true;

    public ?int $numberOfLines = 100;

    public bool $expandByDefault = false;

    public bool $collapsible = true;

    /** Why logs cannot be shown, such as a Sentinel without the container logs capability. */
    #[Locked]
    public ?string $logsUnavailableMessage = null;

    public function mount()
    {
        if ($this->resource instanceof NodeWorkload) {
            return;
        }
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
    public function getLogs(?string $since = null): string
    {
        if (! $this->canReadLogs()) {
            return 'Unauthorized.';
        }
        if ($this->server instanceof Node) {
            return $this->getClusterLogs($this->clusterLogTail(), $since);
        }
        if (! Server::ownedByCurrentTeam()->where('id', $this->server->id)->exists()) {
            return 'Unauthorized.';
        }
        if (! $this->server->isFunctional()) {
            return '';
        }
        if ($this->container && ! ValidationPatterns::isValidContainerName($this->container)) {
            return 'Invalid container name.';
        }
        if (! $this->container) {
            return '';
        }

        if (is_string($since) && preg_match(self::DOCKER_TIMESTAMP_PATTERN, $since)) {
            $options = "--since {$since} -t";
        } else {
            $options = "-n {$this->resolveLogTail()} -t";
        }

        return $this->runLogCommand($this->logCommand($options), self::MAX_DISPLAY_SIZE_BYTES, '5MB');
    }

    public function copyLogs(): string
    {
        if (! $this->canReadLogs()) {
            return '';
        }
        if ($this->server instanceof Node) {
            return sanitizeLogsForExport($this->exportClusterLogs($this->clusterLogTail(), self::MAX_DISPLAY_SIZE_BYTES));
        }
        if (! Server::ownedByCurrentTeam()->where('id', $this->server->id)->exists()) {
            return '';
        }
        if (! $this->server->isFunctional() || ! $this->container) {
            return '';
        }
        if (! ValidationPatterns::isValidContainerName($this->container)) {
            return '';
        }

        $options = "-n {$this->resolveLogTail()}".($this->showTimeStamps ? ' -t' : '');

        return sanitizeLogsForExport($this->runLogCommand($this->logCommand($options), self::MAX_DISPLAY_SIZE_BYTES, '5MB', $this->showTimeStamps), $this->logRedactionSecrets());
    }

    public function downloadAllLogs(): string
    {
        if (! $this->canReadLogs()) {
            return '';
        }
        if ($this->server instanceof Node) {
            return sanitizeLogsForExport($this->exportClusterLogs(FetchContainerLogs::MAX_LINES, self::MAX_DOWNLOAD_SIZE_BYTES));
        }
        if (! Server::ownedByCurrentTeam()->where('id', $this->server->id)->exists()) {
            return '';
        }
        if (! $this->server->isFunctional() || ! $this->container) {
            return '';
        }
        if (! ValidationPatterns::isValidContainerName($this->container)) {
            return '';
        }

        $command = $this->logCommand($this->showTimeStamps ? '-t' : '');

        return sanitizeLogsForExport($this->runLogCommand($command, self::MAX_DOWNLOAD_SIZE_BYTES, '50MB', $this->showTimeStamps), $this->logRedactionSecrets());
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

    private function logCommand(string $options): string
    {
        $options = trim($options);
        $options = $options === '' ? '' : "{$options} ";
        $command = $this->server->isSwarm()
            ? "docker service logs {$options}{$this->container}"
            : "docker logs {$options}{$this->container}";

        if ($this->server->isNonRoot()) {
            $command = parseCommandsByLineForSudo(collect($command), $this->server)[0];
        }

        return $command;
    }

    private function runLogCommand(string $command, int $maxBytes, string $limitLabel, bool $timestamped = true): string
    {
        $sshCommand = SshMultiplexingHelper::generateSshCommand($this->server, $this->boundedLogCommand($command, $maxBytes));

        // boundedLogCommand() caps the output at $maxBytes + 1, so one extra byte means truncation.
        $output = Process::timeout(config('constants.ssh.command_timeout'))->run($sshCommand)->output();
        $truncated = strlen($output) > $maxBytes;
        $logs = rtrim(removeAnsiColors($truncated ? substr($output, 0, $maxBytes) : $output), "\n");
        if ($timestamped) {
            // Docker writes stdout and stderr separately, so sort timestamped lines back into order.
            $logs = str($logs)->split('/\n/')->sort(function ($a, $b) {
                return explode(' ', $a)[0] <=> explode(' ', $b)[0];
            })->join("\n");
        }

        if ($truncated) {
            $logs .= "\n\n[... Output truncated at {$limitLabel} limit ...]";
        }

        return $logs;
    }

    /**
     * Cluster application logs come from Sentinel (`container.logs.v1`), which always sends timestamps.
     * A Docker timestamp in `$since` becomes Sentinel's whole-second `since_unix_seconds`; the browser
     * drops lines it already has.
     */
    private function getClusterLogs(int $lines, ?string $since = null): string
    {
        $sinceUnixSeconds = is_string($since) && preg_match(self::DOCKER_TIMESTAMP_PATTERN, $since)
            ? strtotime(substr($since, 0, 19).'Z')
            : null;

        try {
            $result = FetchContainerLogs::run($this->server, $this->resource, $lines, $sinceUnixSeconds ?: null);
        } catch (\RuntimeException $exception) {
            $this->logsUnavailableMessage = $exception->getMessage();

            return '';
        }

        $this->logsUnavailableMessage = null;

        return $this->formatClusterLogs($result['logs'], $result['truncated'], self::MAX_DISPLAY_SIZE_BYTES, timestamped: true);
    }

    private function exportClusterLogs(int $lines, int $maxBytes): string
    {
        try {
            $result = FetchContainerLogs::run($this->server, $this->resource, $lines);
        } catch (\RuntimeException $exception) {
            $this->dispatch('error', $exception->getMessage());

            return '';
        }

        return $this->formatClusterLogs($result['logs'], $result['truncated'], $maxBytes, (bool) $this->showTimeStamps);
    }

    private function clusterLogTail(): int
    {
        if ($this->numberOfLines === -1 || $this->numberOfLines > FetchContainerLogs::MAX_LINES) {
            $this->numberOfLines = FetchContainerLogs::MAX_LINES;
        }
        if (is_null($this->numberOfLines) || $this->numberOfLines <= 0) {
            $this->numberOfLines = 1000;
        }

        return $this->numberOfLines;
    }

    private function canReadClusterLogs(): bool
    {
        $team = currentTeam();

        return $team !== null
            && $this->server instanceof Node
            && $this->resource instanceof NodeWorkload
            && $this->server->team_id === $team->id
            && $this->resource->team_id === $team->id
            && auth()->user()?->can('update', $this->resource) === true
            && $this->resource->nodes()->whereKey($this->server->id)->exists();
    }

    private function formatClusterLogs(string $logs, bool $truncated, int $maxBytes, bool $timestamped): string
    {
        $outputTruncated = strlen($logs) > $maxBytes;
        $logs = removeAnsiColors($outputTruncated ? substr($logs, 0, $maxBytes) : $logs);
        if (! $timestamped) {
            $logs = (string) preg_replace('/^\d{4}-\d{2}-\d{2}T\S+ /m', '', $logs);
        }
        $logs = rtrim($logs, "\n");
        if ($truncated) {
            $logs .= "\n\n[... Output truncated by Sentinel ...]";
        }
        if ($outputTruncated) {
            $logs .= "\n\n[... Output truncated at ".($maxBytes / 1024 / 1024).'MB limit ...]';
        }

        return $logs;
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
        if ($this->server instanceof Node) {
            return $this->canReadClusterLogs();
        }

        return auth()->user()?->can('update', $this->resource ?? $this->server) ?? false;
    }

    public function render()
    {
        return view('livewire.project.shared.get-logs', [
            'canReadLogs' => $this->canReadLogs(),
        ]);
    }
}
