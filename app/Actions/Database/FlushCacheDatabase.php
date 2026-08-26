<?php

namespace App\Actions\Database;

use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneRedis;
use Lorisleiva\Actions\Concerns\AsAction;

class FlushCacheDatabase
{
    use AsAction;

    public function handle(StandaloneRedis|StandaloneKeydb|StandaloneDragonfly $database): void
    {
        $server = $database->destination->server;

        if (! $server->isFunctional()) {
            throw new \RuntimeException('Server is not functional.');
        }

        if (! $database->isRunning()) {
            throw new \RuntimeException('Database is not running.');
        }

        $command = $this->buildFlushCommand($database->uuid, $this->resolvePassword($database));

        instant_remote_process(command: [$command], server: $server, throwError: true);
    }

    /**
     * Build the `redis-cli FLUSHALL ASYNC` command executed inside the running container.
     *
     * The command connects over the container's local plaintext port (available even when
     * SSL is enabled, because Coolify never disables it), so no TLS flags are needed. The
     * password is shell-escaped so it cannot break out of the argument and inject commands.
     */
    public function buildFlushCommand(string $containerName, ?string $password): string
    {
        $redisCli = 'redis-cli';
        if (filled($password)) {
            $redisCli .= ' -a '.escapeshellarg($password);
        }

        return "docker exec {$containerName} {$redisCli} FLUSHALL ASYNC";
    }

    private function resolvePassword(StandaloneRedis|StandaloneKeydb|StandaloneDragonfly $database): ?string
    {
        return match (true) {
            $database instanceof StandaloneRedis => $database->redis_password,
            $database instanceof StandaloneKeydb => $database->keydb_password,
            $database instanceof StandaloneDragonfly => $database->dragonfly_password,
        };
    }
}
