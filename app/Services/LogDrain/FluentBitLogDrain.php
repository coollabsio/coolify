<?php

namespace App\Services\LogDrain;

/**
 * A drain that runs the coolify-log-drain Fluent Bit container. Containers send their logs
 * to it with the Docker fluentd logging driver.
 */
abstract class FluentBitLogDrain implements LogDrainDriver
{
    public function containerLogging(array $config): array
    {
        return generate_fluentd_configuration();
    }

    /**
     * The files of the Fluent Bit container. Values in `env` go to its `.env` file, and the
     * config reads them as `${NAME}`, so that no config value is written into the config itself.
     *
     * @param  array<string, mixed>  $config
     * @return array{config: string, parsers: string, env: array<string, string>}
     */
    public function fluentBitFiles(array $config, string $serverName): array
    {
        return [
            'config' => $this->pipeline($serverName).$this->output(),
            'parsers' => self::DEFAULT_PARSERS,
            'env' => $this->env($config),
        ];
    }

    /**
     * The `[OUTPUT]` section. It must read config values only as `${NAME}` from env().
     */
    abstract protected function output(): string;

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    abstract protected function env(array $config): array;

    protected const DEFAULT_PARSERS = "
[PARSER]
    Name        empty_line_skipper
    Format      regex
    Regex       /^(?!\s*$).+/
";

    /**
     * The input and the filters that every Fluent Bit drain shares. Rename keeps a key that
     * exists, so the container labels fill a field only when the Coolify default variables are
     * not on the container. Then the labels are removed.
     */
    private function pipeline(string $serverName): string
    {
        return "
[SERVICE]
    Flush     5
    Daemon    off
    Log_Level warn
    Parsers_File  parsers.conf
[INPUT]
    Name              forward
    Buffer_Chunk_Size 1M
    Buffer_Max_Size   6M
[FILTER]
    Name grep
    Match *
    Exclude log 127.0.0.1
[FILTER]
    Name                modify
    Match               *
    Set                 coolify.server_name {$serverName}
    Rename              COOLIFY_APP_NAME coolify.app_name
    Rename              COOLIFY_PROJECT_NAME coolify.project_name
    Rename              COOLIFY_SERVER_IP coolify.server_ip
    Rename              COOLIFY_ENVIRONMENT_NAME coolify.environment_name
    Rename              coolify.resourceName coolify.app_name
    Rename              coolify.projectName coolify.project_name
    Rename              coolify.environmentName coolify.environment_name
    Rename              coolify.serviceName coolify.service_name
    Remove              coolify.resourceName
    Remove              coolify.projectName
    Remove              coolify.environmentName
";
    }
}
