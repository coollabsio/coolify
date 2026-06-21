<?php

namespace App\Livewire\Server\Security;

use App\Actions\Server\CheckUpdates;
use App\Actions\Server\UpdatePackage;
use App\Events\ServerPackageUpdated;
use App\Models\Server;
use App\Notifications\Server\ServerPatchCheck;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Patches extends Component
{
    use AuthorizesRequests;

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
        $this->parameters = get_route_parameters();
        $this->server = Server::ownedByCurrentTeam()->whereUuid($this->parameters['server_uuid'])->firstOrFail();
        $this->authorize('viewSecurity', $this->server);
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
        $this->authorize('update', $this->server);
        if (! $this->packageManager || ! $this->osId) {
            $this->dispatch('error', message: 'Run "Check for updates" first.');

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
            $this->authorize('update', $this->server);
            $activity = UpdatePackage::run(server: $this->server, packageManager: $this->packageManager, osId: $this->osId, package: $package);
            $this->dispatch('activityMonitor', $activity->id, ServerPackageUpdated::class);
        } catch (\Exception $e) {
            $this->dispatch('error', message: $e->getMessage());
        }
    }

    public function sendTestEmail()
    {
        if (! isDev()) {
            $this->dispatch('error', message: 'Test email functionality is only available in development mode.');

            return;
        }

        try {
            // Get current patch data or create test data if none exists
            $testPatchData = $this->createTestPatchData();

            // Send test notification
            $this->server->team->notify(new ServerPatchCheck($this->server, $testPatchData));

            $this->dispatch('success', 'Test email sent successfully! Check your email inbox.');
        } catch (\Exception $e) {
            $this->dispatch('error', message: 'Failed to send test email: '.$e->getMessage());
        }
    }

    private function createTestPatchData(): array
    {
        // If we have real patch data, use it
        if (isset($this->updates) && is_array($this->updates) && count($this->updates) > 0) {
            return [
                'total_updates' => $this->totalUpdates,
                'updates' => $this->updates,
                'osId' => $this->osId,
                'package_manager' => $this->packageManager,
            ];
        }

        // Otherwise create realistic test data
        return [
            'total_updates' => 8,
            'updates' => [
                [
                    'package' => 'docker-ce',
                    'current_version' => '24.0.7-1',
                    'new_version' => '25.0.1-1',
                ],
                [
                    'package' => 'nginx',
                    'current_version' => '1.20.2-1',
                    'new_version' => '1.22.1-1',
                ],
                [
                    'package' => 'kernel-generic',
                    'current_version' => '5.15.0-89',
                    'new_version' => '5.15.0-91',
                ],
                [
                    'package' => 'openssh-server',
                    'current_version' => '8.9p1-3',
                    'new_version' => '9.0p1-1',
                ],
                [
                    'package' => 'curl',
                    'current_version' => '7.81.0-1',
                    'new_version' => '7.85.0-1',
                ],
                [
                    'package' => 'git',
                    'current_version' => '2.34.1-1',
                    'new_version' => '2.39.1-1',
                ],
                [
                    'package' => 'python3',
                    'current_version' => '3.10.6-1',
                    'new_version' => '3.11.0-1',
                ],
                [
                    'package' => 'htop',
                    'current_version' => '3.2.1-1',
                    'new_version' => '3.2.2-1',
                ],
            ],
            'osId' => $this->osId ?? 'ubuntu',
            'package_manager' => $this->packageManager ?? 'apt',
        ];
    }

    public function render()
    {
        return view('livewire.server.security.patches');
    }
}
