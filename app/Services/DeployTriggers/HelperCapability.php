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

    public const FAILED_PROBE_TTL_SECONDS = 300;

    /**
     * A definite answer is kept for a day; a probe that failed (SSH, timeout, image pull) only for a few minutes.
     */
    public function compatible(Server $server): bool
    {
        $image = self::image();
        $key = "affected-capability:{$server->id}:{$image}";
        $cached = Cache::get($key);
        if (is_bool($cached)) {
            return $cached;
        }

        try {
            $output = instant_remote_process(["docker run --rm {$image} coolify-affected version --json"], $server, throwError: true, timeout: 120);
            $version = json_decode((string) $output, true);
            $compatible = is_array($version) && ($version['schema_version'] ?? null) === EvaluateRequest::SCHEMA_VERSION;
            Cache::put($key, $compatible, self::TTL_SECONDS);

            return $compatible;
        } catch (Throwable $exception) {
            $missingBinary = str_contains($exception->getMessage(), 'not found') || (int) $exception->getCode() === 127;
            Cache::put($key, false, $missingBinary ? self::TTL_SECONDS : self::FAILED_PROBE_TTL_SECONDS);

            return false;
        }
    }

    public static function image(): string
    {
        return coolifyHelperImage().':'.getHelperVersion();
    }
}
