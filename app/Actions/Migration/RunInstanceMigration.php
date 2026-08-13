<?php

namespace App\Actions\Migration;

use App\Enums\InstanceMigrationStatus;
use App\Models\InstanceMigration;
use App\Models\PrivateKey;
use App\Models\Server;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

class RunInstanceMigration
{
    use AsAction;

    public string $jobQueue = 'high';

    public int $jobTimeout = 7200;

    public function handle(InstanceMigration $migration, ?Command $command = null): InstanceMigration
    {
        $package = null;

        try {
            if ($migration->dry_run) {
                $this->preflight($migration, $command);
                $migration->markCompleted();
                $command?->info('Dry run complete. Target is reachable and ready for installation.');

                return $migration->refresh();
            }

            $localhost = Server::find(0);
            if (! $localhost) {
                throw new RuntimeException('Coolify host (server id 0) was not found.');
            }

            $oldHostIp = $this->resolveOldHostIp($localhost);
            $migration->update(['old_host_ip' => $oldHostIp]);

            $target = $this->ensureTargetServer($migration);

            $migration->markPhase(InstanceMigrationStatus::Packaging, 'Creating Coolify database dump and packaging keys');
            $command?->info('Packaging instance backup...');
            $package = PackageInstanceBackup::run($localhost);
            $migration->update(['package_paths' => $package]);

            if (DetectIndependentCoolifyInstall::run($target)) {
                $migration->markPhase(InstanceMigrationStatus::Installing, 'Coolify already installed on target, skipping installer');
                $command?->info('Coolify is already installed on '.$migration->target_ip.'; restoring the source database into it.');
            } else {
                $migration->markPhase(InstanceMigrationStatus::Installing, 'Installing Coolify on target');
                $command?->info('Installing Coolify on '.$migration->target_ip.'...');
                InstallCoolifyOnHost::run($target);
            }

            $migration->markPhase(InstanceMigrationStatus::Restoring, 'Restoring Coolify database and SSH keys');
            $command?->info('Restoring Coolify database on target...');
            RestoreInstanceOnHost::run($target, $package);

            $migration->markPhase(InstanceMigrationStatus::SyncingVolumes, 'Copying volumes from all servers to target');
            $command?->info('Syncing persistent volumes to target...');
            $syncItems = SyncAllVolumesToServer::run($migration->team_id, $target);
            $migration->update(['items' => $syncItems]);

            $migration->markPhase(InstanceMigrationStatus::Consolidating, 'Pointing all resources at localhost on target');
            $command?->info('Reassigning destinations on target...');
            ReassignDestinationsOnRemoteInstance::run($target);

            $migration->markPhase(InstanceMigrationStatus::Verifying, 'Checking Coolify dashboard container');
            $dashboardUrl = 'http://'.$migration->target_ip.':8000';
            $this->verifyDashboard($target);

            $migration->markCompleted($dashboardUrl);
            $command?->info('Instance migration completed. Open '.$dashboardUrl);
            $command?->warn('Source resources were left in place. Validate servers and redeploy apps on the new dashboard if needed.');

            return $migration->refresh();
        } catch (Throwable $exception) {
            $migration->markFailed($exception->getMessage());
            $command?->error($exception->getMessage());

            throw $exception;
        } finally {
            if (is_array($package) && isset($package['package_dir']) && is_dir($package['package_dir'])) {
                if ($migration->fresh()->status === InstanceMigrationStatus::Completed) {
                    File::deleteDirectory($package['package_dir']);
                }
            }
        }
    }

    public function asCommand(Command $command): int
    {
        $privateKeyId = (int) $command->option('target-private-key-id');
        if ($privateKeyId < 1) {
            $command->error('--target-private-key-id is required.');

            return 1;
        }

        $key = PrivateKey::find($privateKeyId);
        if (! $key) {
            $command->error('Private key not found.');

            return 1;
        }

        $teamId = currentTeam()?->id ?? $key->team_id;
        $migration = InstanceMigration::create([
            'team_id' => $teamId,
            'status' => InstanceMigrationStatus::Pending,
            'target_ip' => (string) $command->option('target-ip'),
            'target_port' => (int) ($command->option('target-port') ?: 22),
            'target_user' => (string) ($command->option('target-user') ?: 'root'),
            'target_private_key_id' => $key->id,
            'dry_run' => (bool) $command->option('dry-run'),
            'created_by_user_id' => auth()->id(),
            'phases' => [],
            'items' => [],
        ]);

        try {
            $this->handle($migration, $command);

            return 0;
        } catch (Throwable) {
            return 1;
        }
    }

    private function preflight(InstanceMigration $migration, ?Command $command): void
    {
        $target = $this->ensureTargetServer($migration);
        $command?->info('Checking SSH connectivity to '.$migration->target_ip.'...');
        instant_remote_process(['echo ok'], $target, timeout: 30);

        if (DetectIndependentCoolifyInstall::isIndependentInstall(
            (string) (instant_remote_process(["docker ps -a --format '{{.Names}}'"], $target, false, timeout: 15) ?? ''),
            trim((string) (instant_remote_process(['test -f /data/coolify/source/.env && echo yes || echo no'], $target, false, timeout: 15) ?? '')) === 'yes',
        )) {
            throw new RuntimeException('Target already has Coolify installed. Use a fresh VM.');
        }
    }

    private function ensureTargetServer(InstanceMigration $migration): Server
    {
        $existing = Server::where('ip', $migration->target_ip)
            ->where('team_id', $migration->team_id)
            ->first();

        if ($existing) {
            $existing->update([
                'user' => $migration->target_user,
                'port' => $migration->target_port,
                'private_key_id' => $migration->target_private_key_id,
            ]);

            return $existing->fresh();
        }

        $server = new Server;
        $server->forceFill([
            'name' => 'instance-migration-target-'.$migration->uuid,
            'ip' => $migration->target_ip,
            'user' => $migration->target_user,
            'port' => $migration->target_port,
            'team_id' => $migration->team_id,
            'private_key_id' => $migration->target_private_key_id,
            'description' => 'Temporary target for instance migration '.$migration->uuid,
        ]);
        $server->save();
        $server->settings()->update([
            'is_reachable' => true,
            'is_usable' => true,
            'is_build_server' => false,
        ]);

        return $server->fresh();
    }

    private function resolveOldHostIp(Server $localhost): string
    {
        if ($localhost->ip && $localhost->ip !== 'host.docker.internal') {
            return (string) $localhost->ip;
        }

        $detected = trim((string) (instant_remote_process(['hostname -I | awk \'{print $1}\''], $localhost, false) ?? ''));

        return $detected !== '' ? $detected : (string) $localhost->ip;
    }

    private function verifyDashboard(Server $target): void
    {
        $names = (string) (instant_remote_process(['docker ps --format "{{.Names}}"'], $target, false) ?? '');
        foreach (['coolify', 'coolify-db'] as $required) {
            if (! str_contains($names, $required)) {
                throw new RuntimeException("Required container [{$required}] is not running on the target.");
            }
        }
    }
}
