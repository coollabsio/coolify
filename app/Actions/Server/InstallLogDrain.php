<?php

namespace App\Actions\Server;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class InstallLogDrain
{
    use AsAction;

    public function handle(Server $server)
    {
        if ($server->settings->is_logdrain_newrelic_enabled) {
            $type = 'newrelic';
        } elseif ($server->settings->is_logdrain_highlight_enabled) {
            $type = 'highlight';
        } elseif ($server->settings->is_logdrain_axiom_enabled) {
            $type = 'axiom';
        } elseif ($server->settings->is_logdrain_custom_enabled) {
            $type = 'custom';
        } else {
            $type = 'none';
        }
        try {
            if ($type === 'none') {
                $command = [
                    "echo 'Stopping old Fluent Bit'",
                    'docker rm -f coolify-log-drain || true',
                ];

                return instant_remote_process($command, $server);
            } elseif ($type === 'newrelic') {
                if (! $server->settings->is_logdrain_newrelic_enabled) {
                    throw new \Exception('New Relic log drain is not enabled.');
                }
                $config = base64_encode("
[SERVICE]
    Flush     5
    Daemon    off
    Tag container_logs
    Log_Level debug
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
    Set                 server_name {$server->name}
[OUTPUT]
    Name nrlogs
    Match *
    license_key \${LICENSE_KEY}
    # https://log-api.eu.newrelic.com/log/v1 - EU
    # https://log-api.newrelic.com/log/v1 - US
    base_uri \${BASE_URI}
");
            } elseif ($type === 'highlight') {
                if (! $server->settings->is_logdrain_highlight_enabled) {
                    throw new \Exception('Highlight log drain is not enabled.');
                }
                $config = base64_encode('
[SERVICE]
    Flush     5
    Daemon    off
    Log_Level debug
    Parsers_File  parsers.conf
[INPUT]
    Name              forward
    tag               ${HIGHLIGHT_PROJECT_ID}
    Buffer_Chunk_Size 1M
    Buffer_Max_Size   6M
[OUTPUT]
    Name                forward
    Match               *
    Host                otel.highlight.io
    Port                24224
');
            } elseif ($type === 'axiom') {
                if (! $server->settings->is_logdrain_axiom_enabled) {
                    throw new \Exception('Axiom log drain is not enabled.');
                }
                $config = base64_encode("
[SERVICE]
    Flush     5
    Daemon    off
    Log_Level debug
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
    Set                 server_name {$server->name}
[OUTPUT]
    Name            http
    Match           *
    Host            api.axiom.co
    Port            443
    URI             /v1/datasets/\${AXIOM_DATASET_NAME}/ingest
    # Authorization Bearer should be an API token
    Header Authorization Bearer \${AXIOM_API_KEY}
    compress gzip
    format json
    json_date_key _time
    json_date_format iso8601
    tls On
");
            } elseif ($type === 'custom') {
                if (! $server->settings->is_logdrain_custom_enabled) {
                    throw new \Exception('Custom log drain is not enabled.');
                }
                $config = base64_encode($server->settings->logdrain_custom_config);
                $parsers = base64_encode($server->settings->logdrain_custom_config_parser);
            } else {
                throw new \Exception('Unknown log drain type.');
            }
            if ($type !== 'custom') {
                $parsers = base64_encode("
[PARSER]
    Name        empty_line_skipper
    Format      regex
    Regex       /^(?!\s*$).+/
");
            }
            $compose = base64_encode('
services:
  coolify-log-drain:
    image: cr.fluentbit.io/fluent/fluent-bit:2.0
    container_name: coolify-log-drain
    command: -c /fluent-bit.conf
    env_file:
      - .env
    volumes:
      - ./fluent-bit.conf:/fluent-bit.conf
      - ./parsers.conf:/parsers.conf
    ports:
      - 127.0.0.1:24224:24224
    restart: unless-stopped
');
            $readme = base64_encode('# New Relic Log Drain
This log drain is based on [Fluent Bit](https://fluentbit.io/) and New Relic Log Forwarder.

Files:
- `fluent-bit.conf` - configuration file for Fluent Bit
- `docker-compose.yml` - docker-compose file to run Fluent Bit
- `.env` - environment variables for Fluent Bit
');
            $license_key = $server->settings->logdrain_newrelic_license_key;
            $base_uri = $server->settings->logdrain_newrelic_base_uri;
            $base_path = config('coolify.base_config_path');

            $config_path = $base_path.'/log-drains';
            $fluent_bit_config = $config_path.'/fluent-bit.conf';
            $parsers_config = $config_path.'/parsers.conf';
            $compose_path = $config_path.'/docker-compose.yml';
            $readme_path = $config_path.'/README.md';
            $command = [
                "echo 'Saving configuration'",
                "mkdir -p $config_path",
                "echo '{$parsers}' | base64 -d | tee $parsers_config > /dev/null",
                "echo '{$config}' | base64 -d | tee $fluent_bit_config > /dev/null",
                "echo '{$compose}' | base64 -d | tee $compose_path > /dev/null",
                "echo '{$readme}' | base64 -d | tee $readme_path > /dev/null",
                "test -f $config_path/.env && rm $config_path/.env",

            ];
            if ($type === 'newrelic') {
                $add_envs_command = [
                    "echo LICENSE_KEY=$license_key >> $config_path/.env",
                    "echo BASE_URI=$base_uri >> $config_path/.env",
                ];
            } elseif ($type === 'highlight') {
                $add_envs_command = [
                    "echo HIGHLIGHT_PROJECT_ID={$server->settings->logdrain_highlight_project_id} >> $config_path/.env",
                ];
            } elseif ($type === 'axiom') {
                $add_envs_command = [
                    "echo AXIOM_DATASET_NAME={$server->settings->logdrain_axiom_dataset_name} >> $config_path/.env",
                    "echo AXIOM_API_KEY={$server->settings->logdrain_axiom_api_key} >> $config_path/.env",
                ];
            } elseif ($type === 'custom') {
                $add_envs_command = [
                    "touch $config_path/.env",
                ];
            } else {
                throw new \Exception('Unknown log drain type.');
            }
            $restart_command = [
                "echo 'Stopping old Fluent Bit'",
                "cd $config_path && docker compose down --remove-orphans || true",
                "echo 'Starting Fluent Bit'",
                "cd $config_path && docker compose up -d --remove-orphans",
            ];
            $command = array_merge($command, $add_envs_command, $restart_command);

            return instant_remote_process($command, $server);
        } catch (\Throwable $e) {
            return handleError($e);
        }
    }
}
