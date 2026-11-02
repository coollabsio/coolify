<?php

namespace App\Services\Access;

use App\Enums\AccessLevel;
use App\Enums\AccessScope;

/**
 * One access grant: a scope (team, project, environment, or one resource) and the levels it gives there.
 *
 * Stored as JSON on `team_user.access` (members) and `personal_access_tokens.scopes` (tokens).
 * Scopes point to records by UUID, so a grant never stores a raw foreign key.
 */
final readonly class Grant
{
    /**
     * @param  list<AccessLevel>  $levels
     */
    public function __construct(
        public AccessScope $scope,
        public ?string $uuid,
        public array $levels,
    ) {}

    /**
     * @param  list<AccessLevel>  $levels
     */
    public static function team(array $levels): self
    {
        return new self(AccessScope::Team, null, $levels);
    }

    /**
     * @param  array{scope?: string, uuid?: string|null, levels?: list<string>}  $data
     */
    public static function fromArray(array $data): ?self
    {
        $scope = AccessScope::tryFrom((string) ($data['scope'] ?? ''));
        if ($scope === null) {
            return null;
        }

        $uuid = $scope === AccessScope::Team ? null : ($data['uuid'] ?? null);
        if ($scope !== AccessScope::Team && (! is_string($uuid) || $uuid === '')) {
            return null;
        }

        return new self($scope, $uuid, AccessLevel::parseMany($data['levels'] ?? []));
    }

    /**
     * @return list<self>
     */
    public static function listFrom(mixed $stored): array
    {
        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }
        if (! is_array($stored)) {
            return [];
        }

        $grants = [];
        foreach ($stored as $item) {
            $grant = is_array($item) ? self::fromArray($item) : null;
            if ($grant !== null) {
                $grants[] = $grant;
            }
        }

        return $grants;
    }

    /**
     * @param  list<self>  $grants
     * @return list<array{scope: string, uuid: string|null, levels: list<string>}>
     */
    public static function listToArray(array $grants): array
    {
        return array_map(fn (self $grant) => $grant->toArray(), $grants);
    }

    /**
     * @return array{scope: string, uuid: string|null, levels: list<string>}
     */
    public function toArray(): array
    {
        return [
            'scope' => $this->scope->value,
            'uuid' => $this->uuid,
            'levels' => array_map(fn (AccessLevel $level) => $level->value, $this->levels),
        ];
    }
}
