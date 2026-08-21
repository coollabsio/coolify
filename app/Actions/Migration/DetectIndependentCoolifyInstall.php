<?php

namespace App\Actions\Migration;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

class DetectIndependentCoolifyInstall
{
    use AsAction;

    /**
     * Container names that belong to a Coolify control-plane install.
     * Managed application servers only run coolify-proxy / sentinel / helper.
     *
     * @var list<string>
     */
    public const CONTROL_PLANE_NAMES = [
        'coolify',
        'coolify-db',
        'coolify-redis',
        'coolify-realtime',
    ];

    public function handle(Server $server): bool
    {
        if ($server->isLocalhost() || $server->isCoolifyHost) {
            return false;
        }

        if (app()->environment('testing')) {
            return false;
        }

        try {
            $output = instant_remote_process([
                "docker ps -a --format '{{.Names}}'; echo '---SOURCE_ENV---'; test -f /data/coolify/source/.env && echo yes || echo no",
            ], $server, false, timeout: 10) ?? '';

            [$names, $sourceEnv] = array_pad(explode('---SOURCE_ENV---', (string) $output, 2), 2, 'no');

            return self::isIndependentInstall(
                containerNamesOutput: $names,
                hasSourceEnv: trim($sourceEnv) === 'yes',
            );
        } catch (Throwable) {
            return false;
        }
    }

    public static function isIndependentInstall(string $containerNamesOutput, bool $hasSourceEnv = false): bool
    {
        if ($hasSourceEnv) {
            return true;
        }

        $names = collect(preg_split('/\R/', trim($containerNamesOutput)) ?: [])
            ->map(fn (string $line): string => ltrim(trim($line), '/'))
            ->filter();

        if ($names->contains('coolify')) {
            return true;
        }

        return $names->intersect(['coolify-db', 'coolify-redis', 'coolify-realtime'])->count() >= 2;
    }
}
