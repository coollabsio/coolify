<?php

namespace App\Services\DeployTriggers\Contract;

final readonly class EvaluateResponse
{
    /**
     * @param  array<string, ApplicationDecision>  $applications
     */
    public function __construct(
        public string $after,
        public array $applications,
    ) {}
}
