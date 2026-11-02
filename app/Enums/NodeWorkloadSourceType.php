<?php

namespace App\Enums;

/**
 * Where the image of a cluster application comes from. Railpack also builds
 * static sites, so there is no separate static type.
 */
enum NodeWorkloadSourceType: string
{
    case IMAGE = 'image';
    case DOCKERFILE = 'dockerfile';
    case RAILPACK = 'railpack';

    /** Whether Coolify builds the image from a Git repository. */
    public function builds(): bool
    {
        return $this !== self::IMAGE;
    }

    public function label(): string
    {
        return match ($this) {
            self::IMAGE => 'Docker image',
            self::DOCKERFILE => 'Dockerfile',
            self::RAILPACK => 'Railpack',
        };
    }
}
