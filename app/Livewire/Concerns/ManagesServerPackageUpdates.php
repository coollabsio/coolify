<?php

namespace App\Livewire\Concerns;

use App\Actions\Server\RebootServer;
use App\Actions\Server\RemoveUnusedPackages;
use App\Actions\Server\UpdatePackage;

/**
 * Package updates and the reboot of a server, from the saved result of the last patch check.
 * The component must have a public `$server` and authorize `update` before it calls these methods.
 */
trait ManagesServerPackageUpdates
{
    public function rebootServer()
    {
        try {
            $this->authorize('update', $this->server);
            $this->ensureManagedHere();
            try {
                RebootServer::run($this->server);
            } catch (\Throwable $e) {
                $this->auditPatchAction('reboot_requested', ['result' => 'failed', 'error' => class_basename($e)], 'error');

                throw $e;
            }
            $this->auditPatchAction('reboot_requested', ['result' => 'started']);
            $this->dispatch('success', 'Reboot started. The server is back in a few minutes, and Coolify then checks it again.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    /**
     * Removes packages that nothing needs anymore and old kernels (apt autoremove or the equivalent).
     */
    public function removeUnusedPackages()
    {
        try {
            $this->authorize('update', $this->server);
            $this->ensureManagedHere();
            $packageManager = data_get($this->server->settings->patch_check_result, 'package_manager');
            $activity = RemoveUnusedPackages::run($this->server, $packageManager);
            if (is_array($activity)) {
                $this->dispatch('error', message: $activity['error']);

                return;
            }
            $this->auditPatchAction('packages_update_requested', [
                'packages' => 'unused',
                'package_manager' => $packageManager,
                'activity_id' => $activity->id,
            ]);
            $this->dispatch('startupdate');
            $this->dispatch('activityMonitor', $activity->id);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    /**
     * @param  list<string>  $packages
     * @return bool True when the update started.
     */
    private function runUpdate(bool $all = false, array $packages = []): bool
    {
        $this->ensureManagedHere();
        $savedResult = $this->server->settings->patch_check_result;
        $packageManager = data_get($savedResult, 'package_manager');
        $osId = data_get($savedResult, 'osId');
        if (! $packageManager || ! $osId) {
            $this->dispatch('error', message: 'Run "Check for updates" first.');

            return false;
        }

        $activity = UpdatePackage::run(server: $this->server, osId: $osId, packages: $packages, packageManager: $packageManager, all: $all);
        if (is_array($activity)) {
            $this->dispatch('error', message: $activity['error'] ?? 'Something went wrong.');

            return false;
        }
        $this->auditPatchAction('packages_update_requested', [
            'packages' => $all ? 'all' : $packages,
            'package_manager' => $packageManager,
            'activity_id' => $activity->id,
        ]);
        $this->dispatch('startupdate');
        $this->dispatch('activityMonitor', $activity->id);

        return true;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function auditPatchAction(string $action, array $context, string $level = 'info'): void
    {
        auditLog("ui.server.{$action}", [
            'team_id' => $this->server->team_id,
            'server_uuid' => $this->server->uuid,
            'server_name' => $this->server->name,
            ...$context,
        ], $level);
    }

    /**
     * A transferred server is an archive: another instance manages the host.
     */
    private function ensureManagedHere(): void
    {
        if ($this->server->isTransferredAway()) {
            throw new \RuntimeException('This server is managed by another Coolify instance.');
        }
    }
}
