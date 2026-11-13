<?php

namespace App\Services\ProjectConfig;

use App\Models\Environment;
use App\Models\EnvironmentConfigLink;
use App\Models\User;

/**
 * What an adapter needs to read or write resources of one environment.
 */
final class ApplyContext
{
    /** @var array<string, string> `resource.<key>.<property>` => value, filled during an apply */
    public array $resolvedRefs = [];

    public function __construct(
        public readonly EnvironmentConfigLink $link,
        public readonly Environment $environment,
        public readonly int $teamId,
        public readonly ?User $actor = null,
    ) {}

    public static function for(EnvironmentConfigLink $link, ?User $actor = null): self
    {
        $environment = $link->environment;

        return new self($link, $environment, (int) $environment->project->team_id, $actor);
    }
}
