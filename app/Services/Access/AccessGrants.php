<?php

namespace App\Services\Access;

use App\Enums\AccessLevel;

/**
 * The grants of one actor (a member or a token) in one team, with UUIDs resolved to IDs.
 *
 * A grant on a parent applies to its children: team → project → environment → resource.
 * The levels of all matching grants add up.
 */
final class AccessGrants
{
    /**
     * @param  array<string, true>  $teamLevels  expanded levels of team-wide grants
     * @param  array<int, array<string, true>>  $projectLevels  project ID => expanded levels
     * @param  array<int, array<string, true>>  $environmentLevels  environment ID => expanded levels
     * @param  array<string, array<string, true>>  $resourceLevels  resource UUID => expanded levels
     * @param  array<int, int>  $environmentProjects  environment ID => project ID, for granted environments and parents of granted resources
     * @param  array<string, int>  $resourceEnvironments  resource UUID => environment ID
     * @param  list<int>  $projectEnvironmentIds  environments inside granted projects
     */
    public function __construct(
        private readonly array $teamLevels,
        private readonly array $projectLevels,
        private readonly array $environmentLevels,
        private readonly array $resourceLevels,
        private readonly array $environmentProjects,
        private readonly array $resourceEnvironments,
        private readonly array $projectEnvironmentIds,
    ) {}

    public static function unrestricted(): self
    {
        return new self(self::expand([AccessLevel::Root]), [], [], [], [], [], []);
    }

    /**
     * @param  list<AccessLevel>  $levels
     * @return array<string, true>
     */
    public static function expand(array $levels): array
    {
        $expanded = [];
        foreach ($levels as $level) {
            foreach ($level->includes() as $included) {
                $expanded[$included->value] = true;
            }
        }

        return $expanded;
    }

    /**
     * True when a team-wide grant exists, so every project in the team is visible.
     */
    public function coversWholeTeam(): bool
    {
        return $this->teamLevels !== [];
    }

    public function allows(AccessLevel $level, ?int $projectId = null, ?int $environmentId = null, ?string $resourceUuid = null): bool
    {
        if (isset($this->teamLevels[$level->value])) {
            return true;
        }
        if ($projectId !== null && isset($this->projectLevels[$projectId][$level->value])) {
            return true;
        }
        if ($environmentId !== null && isset($this->environmentLevels[$environmentId][$level->value])) {
            return true;
        }

        return $resourceUuid !== null && isset($this->resourceLevels[$resourceUuid][$level->value]);
    }

    /**
     * Projects the actor can open: granted projects and parents of granted environments and resources.
     *
     * @return list<int>
     */
    public function visibleProjectIds(): array
    {
        return array_values(array_unique([
            ...array_keys($this->projectLevels),
            ...array_values($this->environmentProjects),
        ]));
    }

    /**
     * Environments the actor can open, including parents of granted resources.
     *
     * @return list<int>
     */
    public function visibleEnvironmentIds(): array
    {
        return array_values(array_unique([
            ...$this->fullEnvironmentIds(),
            ...array_values($this->resourceEnvironments),
        ]));
    }

    /**
     * Environments where every resource is visible (granted directly or through the project).
     *
     * @return list<int>
     */
    public function fullEnvironmentIds(): array
    {
        return array_values(array_unique([
            ...array_keys($this->environmentLevels),
            ...$this->projectEnvironmentIds,
        ]));
    }

    /**
     * @return list<string>
     */
    public function resourceUuids(): array
    {
        return array_map('strval', array_keys($this->resourceLevels));
    }
}
