<?php

namespace App\Enums;

/**
 * "Block deploys" (spec 15.8): which problems of a new image stop a deploy.
 */
enum ImageScanGateMode: string
{
    case Off = 'off';
    case On = 'on';
    case Strict = 'strict';

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Off',
            self::On => 'On',
            self::Strict => 'Strict',
        };
    }

    public function description(): ?string
    {
        return match ($this) {
            self::Off => null,
            self::On => 'Blocks a deploy when the new image adds a problem that attackers are using or are likely to use.',
            self::Strict => 'Also blocks critical and high problems that have a fix, and operating systems that no longer get security fixes.',
        };
    }
}
