<?php

namespace App\Enums;

enum ImageScanOnError: string
{
    case Deploy = 'deploy';
    case Block = 'block';

    public function label(): string
    {
        return match ($this) {
            self::Deploy => 'Deploy anyway',
            self::Block => 'Block the deploy',
        };
    }
}
