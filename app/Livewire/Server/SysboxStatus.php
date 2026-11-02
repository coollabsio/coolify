<?php

namespace App\Livewire\Server;

use App\Actions\Server\InstallSysbox;
use App\Models\Server;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * A separate component, so that its requests do not send the unsaved fields of the runner form.
 * Otherwise the check would store a new Docker mode in the form snapshot and hide the unsaved bar.
 */
#[Lazy]
class SysboxStatus extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public bool $isInstalled = false;

    protected $listeners = ['sysboxInstalled' => 'checkSysbox'];

    public function mount(Server $server): void
    {
        $this->authorize('view', $server);
        $this->server = $server;
        $this->checkSysbox();
    }

    public function checkSysbox(): void
    {
        $this->authorize('view', $this->server);
        $this->isInstalled = InstallSysbox::isInstalled($this->server);
    }

    public function installSysbox()
    {
        $this->authorize('update', $this->server);

        try {
            $activity = InstallSysbox::run($this->server);
            $this->dispatch('sysbox-install-started');
            $this->dispatch('activityMonitor', $activity->id, 'sysboxInstalled');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function placeholder(): View
    {
        return view('livewire.server.sysbox-status-placeholder');
    }

    public function render(): View
    {
        return view('livewire.server.sysbox-status');
    }
}
