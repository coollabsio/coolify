<?php

namespace App\Http\Livewire\Project\Shared;

use App\Models\Server;
use Illuminate\Support\Facades\Process;
use Livewire\Component;

class GetLogs extends Component
{
    public string $outputs = '';
    public string $errors = '';
    public Server $server;
    public ?string $container = null;
    public ?bool $streamLogs = false;
    public ?bool $showTimeStamps = true;
    public int $numberOfLines = 100;
    public function doSomethingWithThisChunkOfOutput($output)
    {
        $this->outputs .= removeAnsiColors($output);
    }
    public function instantSave()
    {
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
