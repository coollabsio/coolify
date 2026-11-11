<?php

namespace App\Services\LogDrain;

use App\Enums\LogDrainType;

/**
 * The flat log drain fields of the server settings before `logdrain_type` and
 * `logdrain_config`. The log drains API keeps them, and older transfer bundles contain them.
 */
class LegacyLogDrainFields
{
    /**
     * One flag for each type. When more than one flag is on, the first one wins.
     */
    public const FLAGS = [
        'is_logdrain_newrelic_enabled' => LogDrainType::NewRelic,
        'is_logdrain_axiom_enabled' => LogDrainType::Axiom,
        'is_logdrain_custom_enabled' => LogDrainType::Custom,
        'is_logdrain_cloudwatch_enabled' => LogDrainType::Cloudwatch,
    ];

    /**
     * field => [type, config key]
     */
    public const FIELDS = [
        'logdrain_newrelic_license_key' => [LogDrainType::NewRelic, 'license_key'],
        'logdrain_newrelic_base_uri' => [LogDrainType::NewRelic, 'base_uri'],
        'logdrain_axiom_dataset_name' => [LogDrainType::Axiom, 'dataset_name'],
        'logdrain_axiom_api_key' => [LogDrainType::Axiom, 'api_key'],
        'logdrain_axiom_domain' => [LogDrainType::Axiom, 'domain'],
        'logdrain_custom_config' => [LogDrainType::Custom, 'config'],
        'logdrain_custom_config_parser' => [LogDrainType::Custom, 'parser_config'],
        'logdrain_cloudwatch_region' => [LogDrainType::Cloudwatch, 'region'],
        'logdrain_cloudwatch_group' => [LogDrainType::Cloudwatch, 'group'],
        'logdrain_cloudwatch_stream_prefix' => [LogDrainType::Cloudwatch, 'stream_prefix'],
    ];

    /**
     * @return array{label: string, rules: list<mixed>, required?: bool, secret?: bool, default?: string}
     */
    public static function field(string $name): array
    {
        [$type, $key] = self::FIELDS[$name];

        return $type->driver()->fields()[$key];
    }

    /**
     * Replaces the flat fields of settings data with `logdrain_type` and `logdrain_config`.
     * Data without flat fields is returned unchanged.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function convert(array $settings): array
    {
        $legacyKeys = [...array_keys(self::FLAGS), ...array_keys(self::FIELDS), 'is_logdrain_highlight_enabled', 'logdrain_highlight_project_id'];
        if (array_intersect($legacyKeys, array_keys($settings)) === []) {
            return $settings;
        }

        $type = null;
        foreach (self::FLAGS as $flag => $flagType) {
            if (filter_var($settings[$flag] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $type = $flagType;
                break;
            }
        }
        $config = [];
        foreach (self::FIELDS as $name => [$fieldType, $key]) {
            if (filled($settings[$name] ?? null)) {
                $config[$fieldType->value][$key] = $settings[$name];
            }
        }

        $settings = array_diff_key($settings, array_flip($legacyKeys));
        $settings['logdrain_type'] ??= $type?->value;
        $settings['logdrain_config'] ??= $config === [] ? null : $config;

        return $settings;
    }
}
