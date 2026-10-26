<?php

namespace App\Services\ImageScan;

use App\Enums\ImageScanErrorCode;

final readonly class GateResult
{
    /**
     * @param  list<array{id: string, severity: string, package: string, version: string, fixed_in: ?string, kev: bool}>  $top
     */
    public function __construct(
        public bool $blocked,
        public bool $scanRan,
        public ?ImageScanErrorCode $error,
        public int $count,
        public string $headline,
        public array $top,
    ) {}

    /**
     * @return array{blocked: bool, scan_ran: bool, error: ?string, count: int, headline: string, top: list<array{id: string, severity: string, package: string, version: string, fixed_in: ?string, kev: bool}>}
     */
    public function toArray(): array
    {
        return [
            'blocked' => $this->blocked,
            'scan_ran' => $this->scanRan,
            'error' => $this->error?->value,
            'count' => $this->count,
            'headline' => $this->headline,
            'top' => $this->top,
        ];
    }
}
