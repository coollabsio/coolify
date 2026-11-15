<?php

namespace App\Enums;

/**
 * Where the image of a cluster application comes from. Static serves the base
 * directory as it is: Coolify builds it with Railpack, whose staticfile
 * provider serves a directory with an index.html through Caddy on port 80.
 * An inline Dockerfile is the v4 "Dockerfile" resource: the user pastes it,
 * and Coolify builds it with an empty context, without a Git repository.
 */
enum NodeWorkloadSourceType: string
{
    case IMAGE = 'image';
    case DOCKERFILE = 'dockerfile';
    case RAILPACK = 'railpack';
    case STATIC = 'static';
    case DOCKERFILE_INLINE = 'dockerfile_inline';

    /**
     * The "Docker Compose" choice of the Git create pages. It creates a stack (decision 0023), not
     * one application, so it is not a source type.
     */
    public const COMPOSE_BUILD_PACK = 'dockercompose';

    /** Whether Coolify builds the image from a Git repository. */
    public function builds(): bool
    {
        return $this !== self::IMAGE;
    }

    /** Whether the build clones a Git repository. */
    public function usesGit(): bool
    {
        return $this->builds() && $this !== self::DOCKERFILE_INLINE;
    }

    /** @return list<self> The build packs of a Git application, in the order of the v4 picker. */
    public static function gitBuildPacks(): array
    {
        return [self::RAILPACK, self::STATIC, self::DOCKERFILE];
    }

    /**
     * @param  bool  $withCompose  Adds "Docker Compose", which only a create page offers.
     * @return list<array{value: string, label: string}>
     */
    public static function gitBuildPackOptions(bool $withCompose = false): array
    {
        return [
            ...array_map(fn (self $type): array => ['value' => $type->value, 'label' => $type->label()], self::gitBuildPacks()),
            ...($withCompose ? [['value' => self::COMPOSE_BUILD_PACK, 'label' => 'Docker Compose']] : []),
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::IMAGE => 'Docker image',
            self::DOCKERFILE => 'Dockerfile',
            self::RAILPACK => 'Railpack',
            self::STATIC => 'Static',
            self::DOCKERFILE_INLINE => 'Dockerfile without Git',
        };
    }
}
