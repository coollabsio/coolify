<?php

namespace App\Actions\Development;

use App\Enums\NodeRole;
use App\Models\Node;
use App\Models\PrivateKey;
use App\Models\Server;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class SeedDevelopmentQemuServer
{
    use AsAction;

    public function handle(string $profileName, bool $removeOtherServers = true): Server|Node
    {
        $this->ensureDevelopmentEnvironment();
        $profile = config("development-qemu.profiles.{$profileName}");

        if (! is_array($profile)) {
            throw new InvalidArgumentException("Unknown development QEMU profile: {$profileName}");
        }

        $privateKey = PrivateKey::query()->find(1);

        if (! $privateKey) {
            throw new RuntimeException('Development private key 1 is missing. Run the development database seeders first.');
        }

        if ($removeOtherServers) {
            Server::query()
                ->where('uuid', 'like', 'development-qemu-%')
                ->where('uuid', '!=', $profile['uuid'])
                ->delete();
            Node::query()
                ->where('uuid', 'like', 'development-qemu-%')
                ->where('uuid', '!=', $profile['uuid'])
                ->delete();
        }

        if (($profile['runtime'] ?? null) === 'podman') {
            Server::query()->where('uuid', $profile['uuid'])->delete();

            return Node::query()->updateOrCreate(
                ['uuid' => $profile['uuid']],
                [
                    'name' => $profile['name'],
                    'description' => 'Development-only QEMU virtual machine managed by dev:qemu.',
                    'role' => NodeRole::WORKER,
                    'ip' => $profile['ip'],
                    'port' => 22,
                    'user' => $profile['user'],
                    'team_id' => 0,
                    'private_key_id' => $privateKey->id,
                    'sentinel_url' => sprintf(
                        'http://%s:%d',
                        config('development-qemu.gateway'),
                        config('development-qemu.coolify_host_port'),
                    ),
                    'is_reachable' => false,
                    'is_usable' => false,
                ],
            );
        }

        Node::query()->where('uuid', $profile['uuid'])->delete();

        $server = Server::withTrashed()->where('uuid', $profile['uuid'])->first() ?? new Server;
        $server->forceFill(['uuid' => $profile['uuid']]);
        $server->fill([
            'name' => $profile['name'],
            'description' => 'Development-only QEMU virtual machine managed by dev:qemu.',
            'ip' => $profile['ip'],
            'port' => 22,
            'user' => $profile['user'],
            'team_id' => 0,
            'private_key_id' => $privateKey->id,
        ]);
        $server->deleted_at = null;
        $server->save();

        return $server->fresh();
    }

    private function ensureDevelopmentEnvironment(): void
    {
        if (! in_array(config('app.env'), ['local', 'development', 'dev'], true)) {
            throw new RuntimeException('QEMU VM servers may only be seeded in development environments.');
        }
    }
}
