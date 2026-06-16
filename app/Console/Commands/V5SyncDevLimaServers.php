<?php

namespace App\Console\Commands;

use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use App\Models\V5\Cluster;
use App\Models\V5\Server;
use Illuminate\Console\Command;

class V5SyncDevLimaServers extends Command
{
    protected $signature = 'v5:sync-dev-lima-servers
        {--team-id=0 : Team that owns the dev servers}
        {--user-id=0 : User recorded as creator}
        {--private-key-id= : Optional private key used by the dev servers}
        {--cluster=Development-Lima : Cluster name for the dev Lima servers}
        {--builder-capacity=2 : Builder capacity to record for each dev server}
        {--server=* : Server as name|host|ssh_user|ssh_port}
        {--force : Allow running outside local/development environments}';

    protected $description = 'Sync development Lima VMs into the v5 server/cluster tables.';

    public function handle(): int
    {
        if (! app()->environment(['local', 'development', 'testing']) && ! $this->option('force')) {
            $this->error('This command is intended for development only. Use --force to override.');

            return self::FAILURE;
        }

        $team = Team::query()->find((int) $this->option('team-id')) ?? Team::query()->orderBy('id')->first();
        $user = User::query()->find((int) $this->option('user-id')) ?? User::query()->orderBy('id')->first();
        $privateKeyId = $this->option('private-key-id');
        $privateKey = is_numeric($privateKeyId) ? PrivateKey::query()->find((int) $privateKeyId) : null;

        if (! $team instanceof Team || ! $user instanceof User) {
            $this->warn('Cannot sync dev Lima servers without an existing team and user.');

            return self::SUCCESS;
        }

        $servers = $this->option('server');

        if (! is_array($servers) || $servers === []) {
            $this->warn('No dev Lima servers were provided.');

            return self::SUCCESS;
        }

        $cluster = Cluster::query()->updateOrCreate([
            'team_id' => $team->id,
            'name' => (string) $this->option('cluster'),
        ], [
            'created_by_user_id' => $user->id,
            'description' => 'Local Lima development cluster managed by scripts/dev.sh.',
        ]);

        $builderCapacity = max(0, (int) $this->option('builder-capacity'));
        $builderEnabled = $builderCapacity > 0;
        $capabilities = $builderEnabled ? ['coold', 'builder'] : ['coold'];

        foreach ($servers as $server) {
            $parts = explode('|', (string) $server);

            if (count($parts) !== 4) {
                $this->error("Invalid server '{$server}'. Expected name|host|ssh_user|ssh_port.");

                return self::FAILURE;
            }

            [$name, $host, $sshUser, $sshPort] = $parts;

            Server::query()->updateOrCreate([
                'team_id' => $team->id,
                'host' => $host,
                'ssh_port' => (int) $sshPort,
            ], [
                'cluster_id' => $cluster->id,
                'created_by_user_id' => $user->id,
                'private_key_id' => $privateKey?->id,
                'name' => $name,
                'ssh_user' => $sshUser,
                'status' => 'installed',
                'capabilities' => $capabilities,
                'builder_enabled' => $builderEnabled,
                'builder_capacity' => $builderCapacity,
                'last_bootstrapped_at' => now(),
            ]);

            $this->info("Synced {$name} ({$host}:{$sshPort}).");
        }

        return self::SUCCESS;
    }
}
