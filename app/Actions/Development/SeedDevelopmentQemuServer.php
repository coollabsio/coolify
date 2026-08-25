<?php

namespace App\Actions\Development;

use App\Models\PrivateKey;
use App\Models\Server;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class SeedDevelopmentQemuServer
{
    use AsAction;

    public function handle(string $profileName, bool $removeOtherServers = true): Server
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
        }

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
