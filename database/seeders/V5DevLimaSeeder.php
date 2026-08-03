<?php

namespace Database\Seeders;

use App\Actions\V5\Server\SyncDevLimaServers;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;

class V5DevLimaSeeder extends Seeder
{
    private const CLUSTER_NAME = 'Development-Lima';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $team = Team::query()->find(0) ?? Team::query()->orderBy('id')->first();
        $user = User::query()->find(0) ?? User::query()->orderBy('id')->first();

        if (! $team instanceof Team || ! $user instanceof User) {
            return;
        }

        $privateKey = PrivateKey::query()
            ->where('team_id', $team->id)
            ->where('is_git_related', false)
            ->orderBy('id')
            ->first();

        $sshUser = (string) config('coold.dev_ssh_user', 'coolify');
        $servers = collect($this->servers())
            ->map(fn (array $server): array => [
                ...$server,
                'ssh_user' => $sshUser,
            ])
            ->all();

        SyncDevLimaServers::run(
            team: $team,
            user: $user,
            privateKey: $privateKey,
            clusterName: self::CLUSTER_NAME,
            servers: $servers,
        );
    }

    /**
     * @return array<int, array{name: string, host: string, ssh_port: int, wireguard_management_ip: string, wireguard_listen_port_override: int, wireguard_endpoint_override: string}>
     */
    private function servers(): array
    {
        return [
            [
                'name' => 'coold-dev',
                'host' => 'coold-dev.local',
                'ssh_port' => 22,
                'wireguard_management_ip' => '100.64.0.1',
                'wireguard_listen_port_override' => 51821,
                'wireguard_endpoint_override' => 'coold-dev.local:51821',
            ],
            [
                'name' => 'coold-dev-2',
                'host' => 'coold-dev-2.local',
                'ssh_port' => 22,
                'wireguard_management_ip' => '100.64.0.2',
                'wireguard_listen_port_override' => 51822,
                'wireguard_endpoint_override' => 'coold-dev-2.local:51822',
            ],
        ];
    }
}
