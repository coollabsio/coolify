<?php

namespace App\Services\ImageScan;

use App\Enums\ImageScanOnError;
use App\Models\Server;

/**
 * The scan rules of one resource after inheritance.
 */
final readonly class ResolvedImageScanPolicy
{
    /**
     * @param  array{gate_mode: string, on_scan_error: string}  $sources  Level each value came from: instance, team, server or app.
     */
    public function __construct(
        public string $gateMode,
        public ImageScanOnError $onScanError,
        public bool $scanEnabled,
        public array $sources,
        public ?Server $scanServer,
    ) {}
}
