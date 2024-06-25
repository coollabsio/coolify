<?php

namespace App\Livewire\Project\Shared;

use App\Models\Server;
use Livewire\Attributes\On;
use Livewire\Component;

class Terminal extends Component
{
    #[On('send-terminal-command')]
    public function sendTerminalCommand($isContainer, $identifier, $serverUuid)
    {
        $server = Server::whereUuid($serverUuid)->firstOrFail();

        if (auth()->user()) {
            $teams = auth()->user()->teams->pluck('id');
            if (! $teams->contains($server->team_id) && ! $teams->contains(0)) {
                throw new \Exception('User is not part of the team that owns this server');
            }
        }

        if ($isContainer) {
            $status = getContainerStatus($server, $identifier);
            if ($status !== 'running') {
                return handleError(new \Exception('Container is not running'), $this);
            }
            $command = generateSshCommand($server, "docker exec -it {$identifier} sh -c 'if [ -f ~/.profile ]; then . ~/.profile; fi; if [ -n \"\$SHELL\" ]; then exec \$SHELL; else sh; fi'");
        } else {
            $command = generateSshCommand($server, "sh -c 'if [ -f ~/.profile ]; then . ~/.profile; fi; if [ -n \"\$SHELL\" ]; then exec \$SHELL; else sh; fi'");
        }

        // ssh command is sent back to frontend then to websocket
        // this is done because the websocket connection is not available here
        // a better solution would be to remove websocket on NodeJS and work with something like
        // 1. Laravel Pusher/Echo connection (not possible without a sdk)
        // 2. Ratchet / Revolt / ReactPHP / Event Loop (possible but hard to implement and huge dependencies)
        // 3. Just found out about this https://github.com/sirn-se/websocket-php, perhaps it can be used
        $this->dispatch('send-back-command', $command);
    }

    public function render()
    {
        return view('livewire.project.shared.terminal');
    }
}
