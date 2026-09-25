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

    /**
     * Seed the Server (or, for Podman profiles, the worker Node) of a development QEMU profile.
     * $removeOtherServers removes other development QEMU records of the same kind; $asLocalhost reuses Server id 0.
     */
    public function handle(string $profileName, bool $removeOtherServers = true, bool $asLocalhost = false): Server|Node
    {
        $this->ensureDevelopmentEnvironment();
        $profile = config("development-qemu.profiles.{$profileName}");

        if (! is_array($profile)) {
            throw new InvalidArgumentException("Unknown development QEMU profile: {$profileName}");
        }

        $isNode = ($profile['runtime'] ?? null) === 'podman';

        if ($isNode && $asLocalhost) {
            throw new InvalidArgumentException("The {$profileName} profile is a worker Node and cannot be used as localhost.");
        }

        $privateKey = PrivateKey::query()->find(1);

        if (! $privateKey) {
            throw new RuntimeException('Development private key 1 is missing. Run the development database seeders first.');
        }

        if ($removeOtherServers) {
            ($isNode ? Node::query() : Server::query())
                ->where('uuid', 'like', 'development-qemu-%')
                ->where('uuid', '!=', $profile['uuid'])
                ->delete();
        }

        if ($isNode) {
            Server::query()->where('uuid', $profile['uuid'])->delete();

            return Node::query()->updateOrCreate(
                ['uuid' => $profile['uuid']],
                [
                    'name' => $profile['name'],
                    'description' => 'Development QEMU virtual machine',
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

        $server = $asLocalhost
            ? (Server::withTrashed()->find(0) ?? new Server)
            : (Server::withTrashed()->where('uuid', $profile['uuid'])->first() ?? new Server);
        $server->forceFill($asLocalhost ? ['id' => 0, 'uuid' => 'localhost'] : ['uuid' => $profile['uuid']]);
        $server->fill([
            'name' => $asLocalhost ? 'localhost' : $profile['name'],
            'description' => 'Development QEMU virtual machine',
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
