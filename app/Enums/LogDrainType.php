<?php

namespace App\Enums;

use App\Services\LogDrain\AxiomLogDrain;
use App\Services\LogDrain\CloudwatchLogDrain;
use App\Services\LogDrain\CustomLogDrain;
use App\Services\LogDrain\FluentBitLogDrain;
use App\Services\LogDrain\LogDrainDriver;
use App\Services\LogDrain\NewRelicLogDrain;

/**
 * The destination of the log drain of a server (`server_settings.logdrain_type`).
 */
enum LogDrainType: string
{
    case NewRelic = 'newrelic';
    case Axiom = 'axiom';
    case Cloudwatch = 'cloudwatch';
    case Custom = 'custom';

    public function driver(): LogDrainDriver
    {
        return match ($this) {
            self::NewRelic => new NewRelicLogDrain,
            self::Axiom => new AxiomLogDrain,
            self::Cloudwatch => new CloudwatchLogDrain,
            self::Custom => new CustomLogDrain,
        };
    }

    public function label(): string
    {
        return $this->driver()->label();
    }

    /**
     * Whether containers send their logs to the coolify-log-drain Fluent Bit container.
     */
    public function usesFluentBit(): bool
    {
        return $this->driver() instanceof FluentBitLogDrain;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $type): array => ['value' => $type->value, 'label' => $type->label()], self::cases());
    }
}
