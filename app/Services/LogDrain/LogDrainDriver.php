<?php

namespace App\Services\LogDrain;

/**
 * One log drain destination. The config is the array that `server_settings.logdrain_config`
 * stores for the type, with the keys of fields().
 */
interface LogDrainDriver
{
    public function label(): string;

    /**
     * The config fields. `rules` check the format of a value that is set, `required` fields must
     * be set before the drain is enabled, and `secret` values are shown only to users who can
     * update the server. A `default` fills an empty field in the form.
     *
     * @return array<string, array{label: string, rules: list<mixed>, required?: bool, secret?: bool, default?: string}>
     */
    public function fields(): array;

    /**
     * The `logging` setting of each container on the server.
     *
     * @param  array<string, mixed>  $config
     * @return array{driver: string, options: array<string, string>}
     */
    public function containerLogging(array $config): array;
}
