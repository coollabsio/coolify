<?php

namespace App\Livewire\Project\Shared;

use App\Helpers\SshMultiplexingHelper;
use App\Models\Application;
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
use Illuminate\Support\Facades\Process;
use Livewire\Component;

class GetLogs extends Component
{
    public const MAX_LOG_LINES = 50000;

    public const MAX_DOWNLOAD_SIZE_BYTES = 50 * 1024 * 1024; // 50MB

    public string $outputs = '';

    public string $errors = '';

    public Application|Service|StandalonePostgresql|StandaloneRedis|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse|null $resource = null;

    public ServiceApplication|ServiceDatabase|null $servicesubtype = null;

    public Server $server;

    public ?string $container = null;

    public ?string $displayName = null;

    public ?string $pull_request = null;

    public ?bool $streamLogs = false;

    public ?bool $showTimeStamps = true;

    public ?int $numberOfLines = 100;

    public bool $expandByDefault = false;

    public bool $collapsible = true;

    public function mount()
    {
        if (! is_null($this->resource)) {
            if ($this->resource->getMorphClass() === \App\Models\Application::class) {
                $this->showTimeStamps = $this->resource->settings->is_include_timestamps;
            } else {
                if ($this->servicesubtype) {
                    $this->showTimeStamps = $this->servicesubtype->is_include_timestamps;
                } else {
                    $this->showTimeStamps = $this->resource->is_include_timestamps;
                }
            }
            if ($this->resource?->getMorphClass() === \App\Models\Application::class) {
                if (str($this->container)->contains('-pr-')) {
                    $this->pull_request = 'Pull Request: '.str($this->container)->afterLast('-pr-')->beforeLast('_')->value();
                }
            }
        }
    }

    public function instantSave()
    {
        if (! is_null($this->resource)) {
            if ($this->resource->getMorphClass() === \App\Models\Application::class) {
                $this->resource->settings->is_include_timestamps = $this->showTimeStamps;
                $this->resource->settings->save();
            }
            if ($this->resource->getMorphClass() === \App\Models\Service::class) {
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
            $this->getLogs(true);
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

    public function getLogs($refresh = false)
    {
        if (! $this->server->isFunctional()) {
            return;
        }
        if (! $refresh && ! $this->expandByDefault && ($this->resource?->getMorphClass() === \App\Models\Service::class || str($this->container)->contains('-pr-'))) {
            return;
        }
        if ($this->numberOfLines <= 0 || is_null($this->numberOfLines)) {
            $this->numberOfLines = 1000;
        }
        if ($this->numberOfLines > self::MAX_LOG_LINES) {
            $this->numberOfLines = self::MAX_LOG_LINES;
        }
        if ($this->container) {
            if ($this->showTimeStamps) {
                if ($this->server->isSwarm()) {
                    $command = "docker service logs -n {$this->numberOfLines} -t {$this->container}";
                    if ($this->server->isNonRoot()) {
                        $command = parseCommandsByLineForSudo(collect($command), $this->server);
                        $command = $command[0];
                    }
                    $sshCommand = SshMultiplexingHelper::generateSshCommand($this->server, $command);
                } else {
                    $command = "docker logs -n {$this->numberOfLines} -t {$this->container}";
                    if ($this->server->isNonRoot()) {
                        $command = parseCommandsByLineForSudo(collect($command), $this->server);
                        $command = $command[0];
                    }
                    $sshCommand = SshMultiplexingHelper::generateSshCommand($this->server, $command);
                }
            } else {
                if ($this->server->isSwarm()) {
                    $command = "docker service logs -n {$this->numberOfLines} {$this->container}";
                    if ($this->server->isNonRoot()) {
                        $command = parseCommandsByLineForSudo(collect($command), $this->server);
                        $command = $command[0];
                    }
                    $sshCommand = SshMultiplexingHelper::generateSshCommand($this->server, $command);
                } else {
                    $command = "docker logs -n {$this->numberOfLines} {$this->container}";
                    if ($this->server->isNonRoot()) {
                        $command = parseCommandsByLineForSudo(collect($command), $this->server);
                        $command = $command[0];
                    }
                    $sshCommand = SshMultiplexingHelper::generateSshCommand($this->server, $command);
                }
            }
            // Collect new logs into temporary variable first to prevent flickering
            // (avoids clearing output before new data is ready)
            // Use array accumulation + implode for O(n) instead of O(n²) string concatenation
            $logChunks = [];
            Process::timeout(config('constants.ssh.command_timeout'))->run($sshCommand, function (string $type, string $output) use (&$logChunks) {
                $logChunks[] = removeAnsiColors($output);
            });
            $newOutputs = implode('', $logChunks);

            if ($this->showTimeStamps) {
                $newOutputs = str($newOutputs)->split('/\n/')->sort(function ($a, $b) {
                    $a = explode(' ', $a);
                    $b = explode(' ', $b);

                    return $a[0] <=> $b[0];
                })->join("\n");
            }

            // Only update outputs after new data is ready (atomic update prevents flicker)
            $this->outputs = $newOutputs;
        }
    }

    public function copyLogs(): string
    {
        return sanitizeLogsForExport($this->outputs);
    }

    public function downloadAllLogs(): string
    {
        if (! $this->server->isFunctional() || ! $this->container) {
            return '';
        }

        if ($this->showTimeStamps) {
            if ($this->server->isSwarm()) {
                $command = "docker service logs -t {$this->container}";
            } else {
                $command = "docker logs -t {$this->container}";
            }
        } else {
            if ($this->server->isSwarm()) {
                $command = "docker service logs {$this->container}";
            } else {
                $command = "docker logs {$this->container}";
            }
        }

        if ($this->server->isNonRoot()) {
            $command = parseCommandsByLineForSudo(collect($command), $this->server);
            $command = $command[0];
        }

        $sshCommand = SshMultiplexingHelper::generateSshCommand($this->server, $command);

        // Use array accumulation + implode for O(n) instead of O(n²) string concatenation
        // Enforce 50MB size limit to prevent memory exhaustion from large logs
        $logChunks = [];
        $accumulatedBytes = 0;
        $truncated = false;

        Process::timeout(config('constants.ssh.command_timeout'))->run($sshCommand, function (string $type, string $output) use (&$logChunks, &$accumulatedBytes, &$truncated) {
            if ($truncated) {
                return;
            }

            $output = removeAnsiColors($output);
            $outputBytes = strlen($output);

            if ($accumulatedBytes + $outputBytes > self::MAX_DOWNLOAD_SIZE_BYTES) {
                $remaining = self::MAX_DOWNLOAD_SIZE_BYTES - $accumulatedBytes;
                if ($remaining > 0) {
                    $logChunks[] = substr($output, 0, $remaining);
                }
                $truncated = true;

                return;
            }

            $logChunks[] = $output;
            $accumulatedBytes += $outputBytes;
        });

        $allLogs = implode('', $logChunks);

        if ($truncated) {
            $allLogs .= "\n\n[... Output truncated at 50MB limit ...]";
        }

        if ($this->showTimeStamps) {
            $allLogs = str($allLogs)->split('/\n/')->sort(function ($a, $b) {
                $a = explode(' ', $a);
                $b = explode(' ', $b);

                return $a[0] <=> $b[0];
            })->join("\n");
        }

        return sanitizeLogsForExport($allLogs);
    }

    public function render()
    {
        return view('livewire.project.shared.get-logs');
    }
}
