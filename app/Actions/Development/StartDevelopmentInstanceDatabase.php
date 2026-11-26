<?php

namespace App\Actions\Development;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Starts a `coolify-db` container on a development VM that acts as the localhost server.
 *
 * The instance backup reads the credentials from this container and dumps it, like on a self-hosted install.
 * The real development database runs on the host, so the VM cannot reach it.
 */
class StartDevelopmentInstanceDatabase
{
    use AsAction;

    public function handle(Server $server): void
    {
        $environment = collect([
            'POSTGRES_USER' => config('database.connections.pgsql.username'),
            'POSTGRES_PASSWORD' => config('database.connections.pgsql.password'),
            'POSTGRES_DB' => config('database.connections.pgsql.database'),
        ])->map(fn ($value, string $key): string => '-e '.escapeshellarg("{$key}={$value}"))->implode(' ');

        instant_remote_process([
            'if ! docker inspect coolify-db >/dev/null 2>&1; then',
            "docker run -d --name coolify-db --restart unless-stopped {$environment} postgres:15-alpine",
            'fi',
            'docker start coolify-db',
        ], $server, disableMultiplexing: true);
    }
}
