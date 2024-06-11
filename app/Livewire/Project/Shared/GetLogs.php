<?php

namespace App\Livewire\Project\Shared;

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
    public string $outputs = '';

    public string $errors = '';

    public Application|Service|StandalonePostgresql|StandaloneRedis|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse|null $resource = null;

    public ServiceApplication|ServiceDatabase|null $servicesubtype = null;

    public Server $server;

    public ?string $container = null;

    public ?string $pull_request = null;

    public ?bool $streamLogs = false;

    public ?bool $showTimeStamps = true;

    public int $numberOfLines = 100;

    public function mount()
    {
        if (! is_null($this->resource)) {
            if ($this->resource->getMorphClass() === 'App\Models\Application') {
                $this->showTimeStamps = $this->resource->settings->is_include_timestamps;
            } else {
                if ($this->servicesubtype) {
                    $this->showTimeStamps = $this->servicesubtype->is_include_timestamps;
                } else {
                    $this->showTimeStamps = $this->resource->is_include_timestamps;
                }
            }
            if ($this->resource?->getMorphClass() === 'App\Models\Application') {
                if (str($this->container)->contains('-pr-')) {
                    $this->pull_request = 'Pull Request: '.str($this->container)->afterLast('-pr-')->beforeLast('_')->value();
                }
            }
        }
    }

    public function doSomethingWithThisChunkOfOutput($output)
    {
        $this->outputs .= removeAnsiColors($output);
    }

    public function instantSave()
    {
        if (! is_null($this->resource)) {
            if ($this->resource->getMorphClass() === 'App\Models\Application') {
                $this->resource->settings->is_include_timestamps = $this->showTimeStamps;
                $this->resource->settings->save();
            }
            if ($this->resource->getMorphClass() === 'App\Models\Service') {
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

    public function getLogs($refresh = false)
    {
        if (! $this->server->isFunctional()) {
            return;
        }
        if (! $refresh && ($this->resource?->getMorphClass() === 'App\Models\Service' || str($this->container)->contains('-pr-'))) {
            return;
        }
        if (! $this->numberOfLines) {
            $this->numberOfLines = 1000;
        }
        if ($this->container) {
            if ($this->showTimeStamps) {
                if ($this->server->isSwarm()) {
                    $command = "docker service logs -n {$this->numberOfLines} -t {$this->container}";
                    if ($this->server->isNonRoot()) {
                        $command = parseCommandsByLineForSudo(collect($command), $this->server);
                        $command = $command[0];
                    }
                    $sshCommand = generateSshCommand($this->server, $command);
                } else {
                    $command = "docker logs -n {$this->numberOfLines} -t {$this->container}";
                    if ($this->server->isNonRoot()) {
                        $command = parseCommandsByLineForSudo(collect($command), $this->server);
                        $command = $command[0];
                    }
                    $sshCommand = generateSshCommand($this->server, $command);
                }
            } else {
                if ($this->server->isSwarm()) {
                    $command = "docker service logs -n {$this->numberOfLines} {$this->container}";
                    if ($this->server->isNonRoot()) {
                        $command = parseCommandsByLineForSudo(collect($command), $this->server);
                        $command = $command[0];
                    }
                    $sshCommand = generateSshCommand($this->server, $command);
                } else {
                    $command = "docker logs -n {$this->numberOfLines} {$this->container}";
                    if ($this->server->isNonRoot()) {
                        $command = parseCommandsByLineForSudo(collect($command), $this->server);
                        $command = $command[0];
                    }
                    $sshCommand = generateSshCommand($this->server, $command);
                }
            }
            if ($refresh) {
                $this->outputs = '';
            }
            Process::run($sshCommand, function (string $type, string $output) {
                $this->doSomethingWithThisChunkOfOutput($output);
            });
            if ($this->showTimeStamps) {
                $this->outputs = str($this->outputs)->split('/\n/')->sort(function ($a, $b) {
                    $a = explode(' ', $a);
                    $b = explode(' ', $b);

                    return $a[0] <=> $b[0];
                })->join("\n");
            }
        }
    }

    public function render()
    {
        return view('livewire.project.shared.get-logs');
    }
}
