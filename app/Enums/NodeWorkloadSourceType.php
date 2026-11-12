<?php

namespace App\Enums;

/**
 * Where the image of a cluster application comes from. Static serves the base
 * directory as it is: Coolify builds it with Railpack, whose staticfile
 * provider serves a directory with an index.html through Caddy on port 80.
 */
enum NodeWorkloadSourceType: string
{
    case IMAGE = 'image';
    case DOCKERFILE = 'dockerfile';
    case RAILPACK = 'railpack';
    case STATIC = 'static';

    /** Whether Coolify builds the image from a Git repository. */
    public function builds(): bool
    {
        return $this !== self::IMAGE;
    }

    /** @return list<self> The build packs of a Git application, in the order of the v4 picker. */
    public static function gitBuildPacks(): array
    {
        return [self::RAILPACK, self::STATIC, self::DOCKERFILE];
    }

    /** @return list<array{value: string, label: string}> */
    public static function gitBuildPackOptions(): array
    {
        return array_map(fn (self $type): array => ['value' => $type->value, 'label' => $type->label()], self::gitBuildPacks());
    }

    public function label(): string
    {
        return match ($this) {
            self::IMAGE => 'Docker image',
            self::DOCKERFILE => 'Dockerfile',
            self::RAILPACK => 'Railpack',
            self::STATIC => 'Static',
        };
    }
}
