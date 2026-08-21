<?php

namespace App\Actions\Server;

use App\Models\Server;
use App\Models\Service;
use Lorisleiva\Actions\Concerns\AsAction;

class StartLogDrain
{
    use AsAction;

    public string $jobQueue = 'high';

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
        if ($type !== 'none') {
            StopLogDrain::run($server);
        }
        try {
            if ($type === 'none') {
                return 'No log drain is enabled.';
            } elseif ($type === 'newrelic') {
                if (! $server->settings->is_logdrain_newrelic_enabled) {
                    throw new \Exception('New Relic log drain is not enabled.');
                }
                $config = base64_encode("
service:
    flush: 5
    daemon: off
    log_level: debug
    parsers_file: parsers.yml
pipeline:
    inputs:
        - name: forward
          buffer_chunk_size: 1M
          buffer_max_size: 6M
          tag: container_logs
    filters:
        - name: grep
          match: '*'
          exclude: log 127.0.0.1
        - name: modify
          match: '*'
          Add:
            - coolify.server_name {$server->name}
          Rename:
            - coolify.name coolify.app_name
            - coolify.projectName coolify.project_name
            - coolify.environmentName coolify.environment_name
    outputs:
        - name: nrlogs
          match: '*'
          license_key: \${LICENSE_KEY}
          # https://log-api.eu.newrelic.com/log/v1 - EU
          # https://log-api.newrelic.com/log/v1 - US
          base_uri: \${BASE_URI}
");
            } elseif ($type === 'highlight') {
                if (! $server->settings->is_logdrain_highlight_enabled) {
                    throw new \Exception('Highlight log drain is not enabled.');
                }
                $config = base64_encode('
service:
    flush: 5
    daemon: off
    log_level: debug
    parsers_file: parsers.yml
pipeline:
    inputs:
        - name: forward
          buffer_chunk_size: 1M
          buffer_max_size: 6M
          tag: ${HIGHLIGHT_PROJECT_ID}
    outputs:
        - name: forward
          match: '*'
          host: otel.highlight.io
          port: 24224
');
            } elseif ($type === 'axiom') {
                if (! $server->settings->is_logdrain_axiom_enabled) {
                    throw new \Exception('Axiom log drain is not enabled.');
                }
                $config = base64_encode("
service:
    flush: 5
    daemon: off
    log_level: debug
    parsers_file: parsers.yml
pipeline:
    inputs:
        - name: forward
          buffer_chunk_size: 1M
          buffer_max_size: 6M
    filters:
        - name: grep
          match: '*'
          exclude: log 127.0.0.1
        - name: modify
          match: '*'
          Add:
            - coolify.server_name {$server->name}
          Rename:
            - coolify.name coolify.app_name
            - coolify.projectName coolify.project_name
            - coolify.environmentName coolify.environment_name
    outputs:
        - name: http
          match: '*'
          host: api.axiom.co
          port: 443
          uri: /v1/datasets/\${AXIOM_DATASET_NAME}/ingest
          header:
            # Authorization Bearer should be an API token
            - Authorization Bearer \${AXIOM_API_KEY}
          compress: gzip
          format: json
          json_date_key _time
          json_date_format: iso8601
          tls: on
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
parsers:
    - name: empty_line_skipper
      format: regex
      regex: '/^(?!\s*$).+/'
");
            }
            $compose = base64_encode('
services:
  coolify-log-drain:
    image: cr.fluentbit.io/fluent/fluent-bit:4.0
    container_name: coolify-log-drain
    command: -c /fluent-bit.yml
    env_file:
      - .env
    volumes:
      - ./fluent-bit.yml:/fluent-bit.yml
      - ./parsers.yml:/parsers.yml
    ports:
      - 127.0.0.1:24224:24224
    labels:
      - coolify.managed=true
    restart: unless-stopped
');
            $readme = base64_encode('# New Relic Log Drain
This log drain is based on [Fluent Bit](https://fluentbit.io/) and New Relic Log Forwarder.

Files:
- `fluent-bit.yml` - configuration file for Fluent Bit
- `docker-compose.yml` - docker-compose file to run Fluent Bit
- `.env` - environment variables for Fluent Bit
');
            $license_key = $server->settings->logdrain_newrelic_license_key;
            $base_uri = $server->settings->logdrain_newrelic_base_uri;
            $base_path = config('constants.coolify.base_config_path');

            $config_path = $base_path.'/log-drains';
            $fluent_bit_config = $config_path.'/fluent-bit.yml';
            $parsers_config = $config_path.'/parsers.yml';
            $compose_path = $config_path.'/docker-compose.yml';
            $readme_path = $config_path.'/README.md';
            if ($type === 'newrelic') {
                $envContent = "LICENSE_KEY={$license_key}\nBASE_URI={$base_uri}\n";
            } elseif ($type === 'highlight') {
                $envContent = "HIGHLIGHT_PROJECT_ID={$server->settings->logdrain_highlight_project_id}\n";
            } elseif ($type === 'axiom') {
                $envContent = "AXIOM_DATASET_NAME={$server->settings->logdrain_axiom_dataset_name}\nAXIOM_API_KEY={$server->settings->logdrain_axiom_api_key}\n";
            } elseif ($type === 'custom') {
                $envContent = '';
            } else {
                throw new \Exception('Unknown log drain type.');
            }
            $envEncoded = base64_encode($envContent);

            $command = [
                "echo 'Saving configuration'",
                "mkdir -p $config_path",
                "echo '{$parsers}' | base64 -d | tee $parsers_config > /dev/null",
                "echo '{$config}' | base64 -d | tee $fluent_bit_config > /dev/null",
                "echo '{$compose}' | base64 -d | tee $compose_path > /dev/null",
                "echo '{$readme}' | base64 -d | tee $readme_path > /dev/null",
                "echo '{$envEncoded}' | base64 -d | tee $config_path/.env > /dev/null",
                "echo 'Starting Fluent Bit'",
                "cd $config_path && docker compose up -d",
            ];
            $command = array_merge($command, $this->logDrainNetworkConnectCommands($server));

            return instant_remote_process($command, $server);
        } catch (\Throwable $e) {
            return handleError($e);
        }
    }

    private function logDrainNetworkConnectCommands(Server $server): array
    {
        if (! $server->isLogDrainEnabled()) {
            return [];
        }

        return $server->services()
            ->with('destination')
            ->where('connect_to_docker_network', true)
            ->get()
            ->map(fn (Service $service) => data_get($service, 'destination.network'))
            ->filter()
            ->unique()
            ->map(fn (string $network) => 'docker network connect '.escapeshellarg($network).' coolify-log-drain >/dev/null 2>&1 || true')
            ->values()
            ->all();
    }
}
