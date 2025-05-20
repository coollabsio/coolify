<?php

namespace App\Livewire\Server\Security;

use App\Actions\Server\CheckUpdates;
use App\Actions\Server\UpdatePackage;
use App\Events\ServerPackageUpdated;
use App\Models\Server;
use Livewire\Component;

class Patches extends Component
{
    public array $parameters;

    public Server $server;

    public ?int $totalUpdates = null;

    public ?array $updates = null;

    public ?string $error = null;

    public ?string $osId = null;

    public ?string $packageManager = null;

    public function getListeners()
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},ServerPackageUpdated" => 'checkForUpdatesDispatch',
        ];
    }

    public function mount()
    {
        if (! auth()->user()->isAdmin()) {
            abort(403);
        }
        $this->parameters = get_route_parameters();
        $this->server = Server::ownedByCurrentTeam()->whereUuid($this->parameters['server_uuid'])->firstOrFail();
    }

    public function checkForUpdatesDispatch()
    {
        $this->totalUpdates = null;
        $this->updates = null;
        $this->error = null;
        $this->osId = null;
        $this->packageManager = null;
        $this->dispatch('checkForUpdatesDispatch');
    }

    public function checkForUpdates()
    {
        $job = CheckUpdates::run($this->server);
        if (isset($job['error'])) {
            $this->error = data_get($job, 'error', 'Something went wrong.');
        } else {
            $this->totalUpdates = data_get($job, 'total_updates', 0);
            $this->updates = data_get($job, 'updates', []);
            $this->osId = data_get($job, 'osId', null);
            $this->packageManager = data_get($job, 'package_manager', null);
        }
    }

    public function updateAllPackages()
    {
        if (! $this->packageManager || ! $this->osId) {
            $this->dispatch('error', message: 'Run “Check for updates” first.');
            return;
        }

        try {
            $activity = UpdatePackage::run(
                server: $this->server,
                packageManager: $this->packageManager,
                osId: $this->osId,
                all: true
            );
            $this->dispatch('activityMonitor', $activity->id, ServerPackageUpdated::class);
        } catch (\Exception $e) {
            $this->dispatch('error', message: $e->getMessage());
        }
    }

    public function updatePackage($package)
    {
        try {
            $activity = UpdatePackage::run(server: $this->server, packageManager: $this->packageManager, osId: $this->osId, package: $package);
            $this->dispatch('activityMonitor', $activity->id, ServerPackageUpdated::class);
        } catch (\Exception $e) {
            $this->dispatch('error', message: $e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.server.security.patches');
    }
}
