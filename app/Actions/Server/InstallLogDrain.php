<?php

namespace App\Actions\Server;

use Lorisleiva\Actions\Concerns\AsAction;
use App\Models\Server;

class InstallLogDrain
{
    use AsAction;
    public function handle(Server $server, string $type)
    {
        if ($type === 'newrelic') {
            if (!$server->settings->is_logdrain_newrelic_enabled) {
                throw new \Exception('New Relic log drain is not enabled.');
            }
            $config = base64_encode("
[SERVICE]
    Flush     5
    Daemon    off
    Tag container_logs
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
        } else if ($type === 'highlight') {
            if (!$server->settings->is_logdrain_highlight_enabled) {
                throw new \Exception('Highlight log drain is not enabled.');
            }
             $config = base64_encode('
[SERVICE]
    Flush     5
    Daemon    off
    Tag container_logs
[INPUT]
    Name              forward
    tag               ${HIGHLIGHT_PROJECT_ID}
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
    Name                forward
    Match               *
    Host                otel.highlight.io
    Port                24224
');
        }

        $compose = base64_encode("
services:
  coolify-log-drain:
    image: cr.fluentbit.io/fluent/fluent-bit:2.0
    container_name: coolify-log-drain
    command: -c /fluent-bit.conf
    env_file:
      - .env
    volumes:
      - ./fluent-bit.conf:/fluent-bit.conf
    ports:
      - 127.0.0.1:24224:24224
");
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

        $config_path = $base_path . '/log-drains';
        $fluent_bit_config = $config_path . '/fluent-bit.conf';
        $compose_path = $config_path . '/docker-compose.yml';
        $readme_path = $config_path . '/README.md';
        $command = [
            "echo 'Saving configuration'",
            "mkdir -p $config_path",
            "echo '{$config}' | base64 -d > $fluent_bit_config",
            "echo '{$compose}' | base64 -d > $compose_path",
            "echo '{$readme}' | base64 -d > $readme_path",
            "rm $config_path/.env || true",

        ];
        if ($type === 'newrelic') {
            $add_envs_command = [
                "echo LICENSE_KEY=$license_key >> $config_path/.env",
                "echo BASE_URI=$base_uri >> $config_path/.env",
            ];
        } else if ($type === 'highlight') {
            $add_envs_command = [
                "echo HIGHLIGHT_PROJECT_ID={$server->settings->logdrain_highlight_project_id} >> $config_path/.env",
            ];
        }
        $restart_command = [
            "echo 'Stopping old Fluent Bit'",
            "cd $config_path && docker rm -f coolify-log-drain || true",
            "echo 'Starting Fluent Bit'",
            "cd $config_path && docker compose up -d --remove-orphans",
        ];
        $command = array_merge($command, $add_envs_command, $restart_command);
        return instant_remote_process($command, $server);
    }
}
