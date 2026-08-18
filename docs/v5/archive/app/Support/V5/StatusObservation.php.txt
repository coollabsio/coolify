<?php

namespace App\Support\V5;

use App\Enums\V5\ApplicationStatus;
use App\Enums\V5\ContainerState;
use App\Enums\V5\IngressStatus;
use App\Enums\V5\ServerStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * Shared status-observation watermarking and enum normalization used by every
 * v5 status write path (the flux webhook, the reconcile job, and the manual
 * refresh endpoint) so out-of-order updates are dropped and raw coold states
 * are normalized identically everywhere.
 */
class StatusObservation
{
    /**
     * A write whose observation timestamp is older than the one already
     * persisted is stale (delivered or computed out of order) and must not
     * clobber the newer state.
     *
     * @param  array<string, mixed>  $logContext
     */
    public static function isStale(?CarbonInterface $observedAt, ?CarbonInterface $currentObservedAt, string $context, array $logContext): bool
    {
        if ($observedAt === null || $currentObservedAt === null || ! $observedAt->lt($currentObservedAt)) {
            return false;
        }

        Log::debug("Dropping stale flux {$context} update.", [
            ...$logContext,
            'observed_at' => $observedAt->toIso8601String(),
            'current_status_observed_at' => $currentObservedAt->toIso8601String(),
        ]);

        return true;
    }

    /**
     * Map a raw status string onto the given status enum. Unknown values are
     * never written to the database: they fall back to the enum's Unknown case
     * and are logged. Returns null only when no raw value is supplied.
     *
     * @param  class-string<ApplicationStatus|ContainerState|IngressStatus|ServerStatus>  $enumClass
     */
    public static function normalize(?string $raw, string $enumClass): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $status = $enumClass::tryFrom(strtolower($raw));

        if ($status === null) {
            Log::warning('Received unknown flux resource status; falling back to unknown.', [
                'raw_status' => $raw,
                'status_enum' => $enumClass,
            ]);

            return $enumClass::Unknown->value;
        }

        return $status->value;
    }
}
