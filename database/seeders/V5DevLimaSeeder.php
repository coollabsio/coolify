<?php

namespace Database\Seeders;

use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use App\Models\V5\Cluster;
use App\Models\V5\Server;
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

        $cluster = Cluster::query()->updateOrCreate([
            'team_id' => $team->id,
            'name' => self::CLUSTER_NAME,
        ], [
            'created_by_user_id' => $user->id,
            'description' => 'Local Lima development cluster managed by scripts/dev.sh.',
        ]);

        $privateKey = PrivateKey::query()
            ->where('team_id', $team->id)
            ->where('is_git_related', false)
            ->orderBy('id')
            ->first();

        $builderCapacity = max(0, (int) config('coold.dev_builder_capacity', 2));
        $builderEnabled = $builderCapacity > 0;
        $capabilities = $builderEnabled ? ['coold', 'builder'] : ['coold'];
        $sshUser = (string) config('coold.dev_ssh_user', get_current_user());

        foreach ($this->servers() as $server) {
            Server::query()->updateOrCreate([
                'team_id' => $team->id,
                'host' => $server['host'],
                'ssh_port' => $server['ssh_port'],
            ], [
                'cluster_id' => $cluster->id,
                'created_by_user_id' => $user->id,
                'private_key_id' => $privateKey?->id,
                'name' => $server['name'],
                'ssh_user' => $sshUser,
                'status' => 'installed',
                'capabilities' => $capabilities,
                'builder_enabled' => $builderEnabled,
                'builder_capacity' => $builderCapacity,
                'last_bootstrapped_at' => now(),
            ]);
        }
    }

    /**
     * @return array<int, array{name: string, host: string, ssh_port: int}>
     */
    private function servers(): array
    {
        return [
            [
                'name' => 'coold-dev',
                'host' => 'lima-coold-dev',
                'ssh_port' => 22,
            ],
            [
                'name' => 'coold-dev-2',
                'host' => 'lima-coold-dev-2',
                'ssh_port' => 22,
            ],
        ];
    }
}
