<?php

namespace App\Services\DeployTriggers;

use App\Models\Server;
use App\Services\DeployTriggers\Contract\EvaluateRequest;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Whether the helper image of a server ships a coolify-affected that speaks this contract version.
 */
class HelperCapability
{
    public const TTL_SECONDS = 86400;

    public function compatible(Server $server): bool
    {
        $image = self::image();

        return Cache::remember("affected-capability:{$server->id}:{$image}", self::TTL_SECONDS, function () use ($server, $image): bool {
            try {
                $output = instant_remote_process(["docker run --rm {$image} coolify-affected version --json"], $server, throwError: true, timeout: 120);
                $version = json_decode((string) $output, true);

                return is_array($version) && ($version['schema_version'] ?? null) === EvaluateRequest::SCHEMA_VERSION;
            } catch (Throwable) {
                return false;
            }
        });
    }

    public static function image(): string
    {
        return coolifyHelperImage().':'.getHelperVersion();
    }
}
