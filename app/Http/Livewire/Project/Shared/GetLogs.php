<?php

namespace App\Http\Livewire\Project\Shared;

use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
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
    public Application|Service|StandalonePostgresql|StandaloneRedis|StandaloneMongodb|StandaloneMysql|StandaloneMariadb $resource;
    public ServiceApplication|ServiceDatabase|null $servicesubtype = null;
    public Server $server;
    public ?string $container = null;
    public ?bool $streamLogs = false;
    public ?bool $showTimeStamps = true;
    public int $numberOfLines = 100;

    public function mount()
    {
        if ($this->resource->getMorphClass() === 'App\Models\Application') {
            $this->showTimeStamps = $this->resource->settings->is_include_timestamps;
        } else {
            if ($this->servicesubtype) {
                $this->showTimeStamps = $this->servicesubtype->is_include_timestamps;
            } else {
                $this->showTimeStamps = $this->resource->is_include_timestamps;
            }
        }
    }
    public function doSomethingWithThisChunkOfOutput($output)
    {
        $this->outputs .= removeAnsiColors($output);
    }
    public function instantSave()
    {
        if ($this->resource->getMorphClass() === 'App\Models\Application') {
            $this->resource->settings->is_include_timestamps = $this->showTimeStamps;
            $this->resource->settings->save();
        } else {
            if ($this->servicesubtype) {
                $this->servicesubtype->is_include_timestamps = $this->showTimeStamps;
                $this->servicesubtype->save();
            } else {
                $this->resource->is_include_timestamps = $this->showTimeStamps;
                $this->resource->save();
            }
        }
    }
    public function getLogs($refresh = false)
    {
        if ($this->container) {
            if ($this->showTimeStamps) {
                $sshCommand = generateSshCommand($this->server, "docker logs -n {$this->numberOfLines} -t {$this->container}");
            } else {
                $sshCommand = generateSshCommand($this->server, "docker logs -n {$this->numberOfLines} {$this->container}");
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
