<?php

namespace App\Actions\Server;

use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\Service;
use App\Rules\ValidFluentBitConfig;
use Illuminate\Support\Facades\Cache;
use Lorisleiva\Actions\Concerns\AsAction;

class StartLogDrain
{
    use AsAction;

    public string $jobQueue = 'high';

    /**
     * Bump when the generated files change (image, config, compose, or README), so that the
     * server checks start running log drains again with the new files.
     */
    public const CONFIG_VERSION = '1';

    public const CONFIG_VERSION_LABEL = 'coolify.logDrainConfigVersion';

    /**
     * Whether a running log drain container was made with older files. Claims the refresh for
     * 10 minutes, so that the frequent server checks queue only one restart.
     */
    public static function claimOutdatedRefresh(Server $server, mixed $labels): bool
    {
        if ((string) collect($labels)->get(self::CONFIG_VERSION_LABEL) === self::CONFIG_VERSION) {
            return false;
        }

        return Cache::add("log-drain-refresh:{$server->uuid}", true, now()->addMinutes(10));
    }

    public function handle(Server $server)
    {
        if ($server->settings->is_logdrain_newrelic_enabled) {
            $type = 'newrelic';
        } elseif ($server->settings->is_logdrain_axiom_enabled) {
            $type = 'axiom';
        } elseif ($server->settings->is_logdrain_custom_enabled) {
            $type = 'custom';
        } elseif ($server->settings->is_logdrain_cloudwatch_enabled) {
            $type = 'cloudwatch';
        } else {
            $type = 'none';
        }
        if ($type === 'cloudwatch') {
            // Containers ship logs directly via Docker's awslogs driver; no Fluent Bit container is needed.
            StopLogDrain::run($server);

            return 'Amazon CloudWatch Logs uses the built-in Docker awslogs driver.';
        }
        $serverName = $this->fluentBitValue((string) $server->name);
        try {
            if ($type === 'none') {
                return 'No log drain is enabled.';
            } elseif ($type === 'newrelic') {
                if (! $server->settings->is_logdrain_newrelic_enabled) {
                    throw new \Exception('New Relic log drain is not enabled.');
                }
                $config = base64_encode("
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
[OUTPUT]
    Name nrlogs
    Match *
    license_key \${LICENSE_KEY}
    # https://log-api.eu.newrelic.com/log/v1 - EU
    # https://log-api.newrelic.com/log/v1 - US
    base_uri \${BASE_URI}
");
            } elseif ($type === 'axiom') {
                if (! $server->settings->is_logdrain_axiom_enabled) {
                    throw new \Exception('Axiom log drain is not enabled.');
                }
                $config = base64_encode("
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
[OUTPUT]
    Name            http
    Match           *
    Host            \${AXIOM_DOMAIN}
    Port            443
    URI             /v1/ingest/\${AXIOM_DATASET_NAME}
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
                $configError = (new ValidFluentBitConfig)->check($server->settings->logdrain_custom_config);
                if ($configError !== null) {
                    throw new \Exception("Custom log drain config is not allowed: {$configError}");
                }
                $parsersError = (new ValidFluentBitConfig(parsersOnly: true))->check($server->settings->logdrain_custom_config_parser);
                if ($parsersError !== null) {
                    throw new \Exception("Custom log drain parser config is not allowed: {$parsersError}");
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
    image: cr.fluentbit.io/fluent/fluent-bit:5.1
    container_name: coolify-log-drain
    command: -c /fluent-bit.conf
    env_file:
      - .env
    volumes:
      - ./fluent-bit.conf:/fluent-bit.conf:ro
      - ./parsers.conf:/parsers.conf:ro
    ports:
      - 127.0.0.1:24224:24224
    labels:
      - coolify.managed=true
      - '.self::CONFIG_VERSION_LABEL.'='.self::CONFIG_VERSION.'
    restart: unless-stopped
');
            $providerName = match ($type) {
                'newrelic' => 'New Relic',
                'axiom' => 'Axiom',
                'custom' => 'Custom',
            };
            $readme = base64_encode("# {$providerName} Log Drain
This log drain is based on [Fluent Bit](https://fluentbit.io/).

Files:
- `fluent-bit.conf` - configuration file for Fluent Bit
- `docker-compose.yml` - docker-compose file to run Fluent Bit
- `.env` - environment variables for Fluent Bit
");
            $license_key = $server->settings->logdrain_newrelic_license_key;
            $base_uri = $server->settings->logdrain_newrelic_base_uri;
            $base_path = config('constants.coolify.base_config_path');

            $config_path = $base_path.'/log-drains';
            $fluent_bit_config = $config_path.'/fluent-bit.conf';
            $parsers_config = $config_path.'/parsers.conf';
            $compose_path = $config_path.'/docker-compose.yml';
            $readme_path = $config_path.'/README.md';
            if ($type === 'newrelic') {
                $envContent = "LICENSE_KEY={$license_key}\nBASE_URI={$base_uri}\n";
            } elseif ($type === 'axiom') {
                $axiomDomain = trim((string) $server->settings->logdrain_axiom_domain) ?: ServerSetting::DEFAULT_AXIOM_DOMAIN;
                $envContent = "AXIOM_DOMAIN={$axiomDomain}\nAXIOM_DATASET_NAME={$server->settings->logdrain_axiom_dataset_name}\nAXIOM_API_KEY={$server->settings->logdrain_axiom_api_key}\n";
            } elseif ($type === 'custom') {
                $envContent = '';
            } else {
                throw new \Exception('Unknown log drain type.');
            }
            $envEncoded = base64_encode($envContent);

            // The running drain is removed only after the checks above and the image pull, so that an
            // invalid custom config or a slow download does not stop log collection.
            $command = [
                "echo 'Saving configuration'",
                "mkdir -p $config_path",
                "echo '{$parsers}' | base64 -d | tee $parsers_config > /dev/null",
                "echo '{$config}' | base64 -d | tee $fluent_bit_config > /dev/null",
                "echo '{$compose}' | base64 -d | tee $compose_path > /dev/null",
                "echo '{$readme}' | base64 -d | tee $readme_path > /dev/null",
                "echo '{$envEncoded}' | base64 -d | tee $config_path/.env > /dev/null",
                "echo 'Pulling Fluent Bit'",
                "docker compose -f $compose_path pull --quiet --ignore-pull-failures",
                "echo 'Starting Fluent Bit'",
                'docker rm -f coolify-log-drain >/dev/null 2>&1 || true',
                "docker compose -f $compose_path up -d",
            ];
            $command = array_merge($command, $this->logDrainNetworkConnectCommands($server));

            return instant_remote_process($command, $server);
        } catch (\Throwable $e) {
            return handleError($e);
        }
    }

    /**
     * Fluent Bit reads its config line by line, so a value with a line break could add sections,
     * and the modify filter splits `Set` on spaces, so the value must be a single token.
     */
    private function fluentBitValue(string $value): string
    {
        return preg_replace('/[\s\x00-\x1F\x7F]+/', '-', trim($value));
    }

    private function logDrainNetworkConnectCommands(Server $server): array
    {
        if (! $server->isFluentBitLogDrainEnabled()) {
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
