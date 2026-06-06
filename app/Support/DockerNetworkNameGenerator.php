<?php

namespace App\Support;

use App\Exceptions\DockerNetworkCreationException;
use App\Models\DockerNetwork;
use App\Models\Server;
use App\Services\Docker\DockerNetworkInspector;
use Closure;
use Visus\Cuid2\Cuid2;

class DockerNetworkNameGenerator
{
    private const PREFIX = 'coolify-net-';

    private const RANDOM_LENGTH = 12;

    private const MAX_ATTEMPTS = 10;

    public static function generate(Server $server, ?Closure $executor = null): string
    {
        $executor ??= fn ($command, $s) => instant_remote_process($command, $s, false);

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $name = self::PREFIX.substr((string) new Cuid2, 0, self::RANDOM_LENGTH);

            if (self::existsInDatabase($server, $name)) {
                continue;
            }

            if (! self::existsInRuntime($server, $name, $executor)) {
                return $name;
            }
        }

        throw new DockerNetworkCreationException('Unable to generate a unique Docker network name after multiple attempts.');
    }

    private static function existsInDatabase(Server $server, string $name): bool
    {
        return DockerNetwork::byServer($server)
            ->byName($name)
            ->exists();
    }

    private static function existsInRuntime(Server $server, string $name, Closure $executor): bool
    {
        return (new DockerNetworkInspector)->rawInspect(
            $server,
            $name,
            fn (Server $server, array $command): ?string => $executor($command, $server),
        ) !== null;
    }
}
