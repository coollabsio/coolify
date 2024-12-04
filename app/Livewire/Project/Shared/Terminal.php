<?php

namespace App\Livewire\Project\Shared;

use App\Helpers\SshMultiplexingHelper;
use App\Models\Server;
use Livewire\Attributes\On;
use Livewire\Component;

class Terminal extends Component
{
    public function getListeners()
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},ApplicationStatusChanged" => 'closeTerminal',
        ];
    }

    public function closeTerminal()
    {
        $this->dispatch('reloadWindow');
    }

    #[On('send-terminal-command')]
    public function sendTerminalCommand($isContainer, $identifier, $serverUuid)
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($serverUuid)->firstOrFail();

        if ($isContainer) {
            // Validate container identifier format (alphanumeric, dashes, and underscores only)
            if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $identifier)) {
                throw new \InvalidArgumentException('Invalid container identifier format');
            }

            // Verify container exists and belongs to the user's team
            $status = getContainerStatus($server, $identifier);
            if ($status !== 'running') {
                return;
            }

            // Escape the identifier for shell usage
            $escapedIdentifier = escapeshellarg($identifier);
            $command = SshMultiplexingHelper::generateSshCommand($server, "docker exec -it {$escapedIdentifier} sh -c 'PATH=\$PATH:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin && if [ -f ~/.profile ]; then . ~/.profile; fi && if [ -n \"\$SHELL\" ]; then exec \$SHELL; else sh; fi'");
        } else {
            $command = SshMultiplexingHelper::generateSshCommand($server, 'PATH=$PATH:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin && if [ -f ~/.profile ]; then . ~/.profile; fi && if [ -n "$SHELL" ]; then exec $SHELL; else sh; fi');
        }

        // ssh command is sent back to frontend then to websocket
        // this is done because the websocket connection is not available here
        // a better solution would be to remove websocket on NodeJS and work with something like
        // 1. Laravel Pusher/Echo connection (not possible without a sdk)
        // 2. Ratchet / Revolt / ReactPHP / Event Loop (possible but hard to implement and huge dependencies)
        // 3. Just found out about this https://github.com/sirn-se/websocket-php, perhaps it can be used
        // 4. Follow-up discussions here:
        //     - https://github.com/coollabsio/coolify/issues/2298
        //     - https://github.com/coollabsio/coolify/discussions/3362
        $this->dispatch('send-back-command', $command);
    }

    public function render()
    {
        return view('livewire.project.shared.terminal');
    }
}
