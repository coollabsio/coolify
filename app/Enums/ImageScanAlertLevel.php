<?php

namespace App\Enums;

enum ImageScanAlertLevel: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';

    public function label(): string
    {
        return match ($this) {
            self::Critical => 'Critical with a fix',
            self::High => 'High or critical with a fix',
            self::Medium => 'Medium or higher with a fix',
        };
    }

    /**
     * Whether a finding of this severity is at or above the alert level.
     */
    public function includes(string $severity): bool
    {
        return in_array(strtolower($severity), match ($this) {
            self::Critical => ['critical'],
            self::High => ['critical', 'high'],
            self::Medium => ['critical', 'high', 'medium'],
        }, true);
    }
}
