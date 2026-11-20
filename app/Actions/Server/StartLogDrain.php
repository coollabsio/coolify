<?php

namespace App\Actions\Server;

use App\Models\Server;
use App\Models\Service;
use App\Services\LogDrain\FluentBitLogDrain;
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
    public const CONFIG_VERSION = '3';

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
        $type = $server->settings->logdrain_type;
        if (! $type) {
            return 'No log drain is enabled.';
        }
        $driver = $type->driver();
        if (! $driver instanceof FluentBitLogDrain) {
            // Containers ship logs directly with a Docker logging driver; no Fluent Bit container is needed.
            StopLogDrain::run($server);
            $loggingDriver = $driver->containerLogging([])['driver'];

            return "{$driver->label()} uses the built-in Docker {$loggingDriver} driver.";
        }

        try {
            $files = $driver->fluentBitFiles($server->settings->logDrainConfig($type), $this->fluentBitValue((string) $server->name));
            $config = base64_encode($files['config']);
            $parsers = base64_encode($files['parsers']);
            $envEncoded = base64_encode($this->envFile($files['env']));
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
            $readme = base64_encode("# {$driver->label()} Log Drain
This log drain is based on [Fluent Bit](https://fluentbit.io/).

Files:
- `fluent-bit.conf` - configuration file for Fluent Bit
- `docker-compose.yml` - docker-compose file to run Fluent Bit
- `.env` - environment variables for Fluent Bit
");
            $base_path = config('constants.coolify.base_config_path');

            $config_path = $base_path.'/log-drains';
            $fluent_bit_config = $config_path.'/fluent-bit.conf';
            $parsers_config = $config_path.'/parsers.conf';
            $compose_path = $config_path.'/docker-compose.yml';
            $readme_path = $config_path.'/README.md';

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
     * Docker Compose reads one `NAME=value` per line, so a value with a line break could add variables.
     *
     * @param  array<string, string>  $env
     */
    private function envFile(array $env): string
    {
        return collect($env)
            ->map(function (string $value, string $name) {
                if (preg_match('/[\r\n\x00]/', $value)) {
                    throw new \RuntimeException("The log drain value {$name} must be on one line.");
                }

                return "{$name}={$value}\n";
            })
            ->implode('');
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
