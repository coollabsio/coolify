<?php

namespace App\Console\Commands;

use App\Actions\V5\Server\SyncDevLimaServers;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use App\Support\V5\V5Feature;
use Illuminate\Console\Command;

class V5SyncDevLimaServers extends Command
{
    protected $signature = 'v5:sync-dev-lima-servers
        {--team-id=0 : Team that owns the dev servers}
        {--user-id=0 : User recorded as creator}
        {--private-key-id= : Optional private key used by the dev servers}
        {--cluster=Development-Lima : Cluster name for the dev Lima servers}
        {--server=* : Server as name|host|ssh_user|ssh_port|wireguard_management_ip}';

    protected $description = 'Sync development Lima VMs into the v5 server/cluster tables.';

    public function handle(): int
    {
        if (! V5Feature::enabled()) {
            $this->error('V5 is only available in development environments.');

            return self::FAILURE;
        }

        $team = Team::query()->find((int) $this->option('team-id')) ?? Team::query()->orderBy('id')->first();
        $user = User::query()->find((int) $this->option('user-id')) ?? User::query()->orderBy('id')->first();
        $privateKeyId = $this->option('private-key-id');
        $privateKey = is_numeric($privateKeyId)
            ? PrivateKey::query()->find((int) $privateKeyId)
            : PrivateKey::query()
                ->where('team_id', $team?->id)
                ->where('is_git_related', false)
                ->orderBy('id')
                ->first();

        if (! $team instanceof Team || ! $user instanceof User) {
            $this->warn('Cannot sync dev Lima servers without an existing team and user.');

            return self::SUCCESS;
        }

        $servers = $this->option('server');

        if (! is_array($servers) || $servers === []) {
            $this->warn('No dev Lima servers were provided.');

            return self::SUCCESS;
        }

        $parsedServers = [];

        foreach ($servers as $server) {
            $parts = explode('|', (string) $server);

            if (! in_array(count($parts), [4, 5], true)) {
                $this->error("Invalid server '{$server}'. Expected name|host|ssh_user|ssh_port|wireguard_management_ip.");

                return self::FAILURE;
            }

            [$name, $host, $sshUser, $sshPort] = array_slice($parts, 0, 4);
            $wireguardManagementIp = ($parts[4] ?? null) ?: null;

            $parsedServers[] = [
                'name' => $name,
                'host' => $host,
                'ssh_user' => $sshUser,
                'ssh_port' => (int) $sshPort,
                'wireguard_management_ip' => $wireguardManagementIp,
            ];
        }

        SyncDevLimaServers::run(
            team: $team,
            user: $user,
            privateKey: $privateKey,
            clusterName: (string) $this->option('cluster'),
            servers: $parsedServers,
        );

        foreach ($parsedServers as $server) {
            $this->info("Synced {$server['name']} ({$server['host']}:{$server['ssh_port']}).");
        }

        return self::SUCCESS;
    }
}
