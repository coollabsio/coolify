<?php

namespace App\Http\Livewire;

use App\Jobs\InstanceAutoUpdateJob;
use App\Models\Server;
use Livewire\Component;

class ForceUpgrade extends Component
{
    public function upgrade()
    {
        try {
            $server_name = 'localhost';
            if (config('app.env') === 'local') {
                $server_name = 'testing-local-docker-container';
            }
            $server = Server::where('name', $server_name)->firstOrFail();
            $this->emit('updateInitiated');
            dispatch(new InstanceAutoUpdateJob(force: true, server: $server));
        } catch (\Exception $e) {
            return general_error_handler($e, $this);
        }
    }
}
