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
    public int $numberOfLines = 100;
    public function doSomethingWithThisChunkOfOutput($output)
    {
        $this->outputs .= $output;
    }
    public function instantSave()
    {
    }
    public function getLogs($refresh = false)
    {
        if ($this->container) {
            $sshCommand = generateSshCommand($this->server, "docker logs -n {$this->numberOfLines} -t {$this->container}");
            if ($refresh) {
                $this->outputs = '';
            }
            Process::run($sshCommand, function (string $type, string $output) {
                $this->doSomethingWithThisChunkOfOutput($output);
            });
        }
    }
    public function render()
    {
        return view('livewire.project.shared.get-logs');
    }
}
