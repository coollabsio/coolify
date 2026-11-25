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
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function storedSet(): ?array
    {
        return ($this->preview ?? $this->application)->trigger_set;
    }

    /**
     * The stored set without its bookkeeping, when it was resolved at the base with today's settings and no error.
     *
     * @return array<string, mixed>|null
     */
    public function validStoredSet(): ?array
    {
        $set = $this->storedSet();
        if (! is_array($set) || $this->base === null
            || ($set['evaluated_commit'] ?? null) !== $this->base
            || ($set['config_fingerprint'] ?? null) !== $this->fingerprint
            || ($set['last_error'] ?? null) !== null
            || ! is_array($set['compiled'] ?? null)
            || ! is_array($set['input_patterns'] ?? null)) {
            return null;
        }

        return array_diff_key($set, array_flip(['evaluated_commit', 'config_fingerprint', 'evaluated_at', 'last_error']));
    }
}
