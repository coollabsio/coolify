<?php

namespace App\Services\DeployTriggers;

use App\Models\Application;
use App\Models\ApplicationPreview;

/**
 * An application (or preview) that a push is evaluated for.
 */
final readonly class TriggerCandidate
{
    /**
     * @param  'smart'|'watch_paths'  $mode
     */
    public function __construct(
        public string $id,
        public Application $application,
        public ?ApplicationPreview $preview,
        public string $mode,
        public ?string $base,
        public bool $isStored,
        public string $fingerprint,
        public bool $scanValid,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function storedSet(): ?array
    {
        return ($this->preview ?? $this->application)->trigger_set;
    }
}
