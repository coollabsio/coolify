<?php

namespace App\Actions\Server;

use App\Models\Server;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

class InstallSentinelHost
{
    use AsAction;

    public function handle(Server $server, ?string $image = null): ?string
    {
        if (! isDev() || ! config('constants.sentinel.host_enabled', false)) {
            return null;
        }

        $token = $server->settings->ensureValidSentinelToken();
        $endpoint = $server->settings->ensureSentinelUrl();
        $image ??= config('constants.sentinel.host_image');
        $script = self::installationScript($token, $endpoint, $image);

        return instant_remote_process(
            ['printf %s '.escapeshellarg(base64_encode($script)).' | base64 -d | bash'],
            $server,
            timeout: 600,
            disableMultiplexing: true,
        );
    }

    public static function installationScript(string $token, string $endpoint, string $image): string
    {
        self::validateInputs($token, $endpoint, $image);

        $environment = self::environmentFile($token, $endpoint);
        $unit = self::serviceUnit();
        $encodedEnvironment = base64_encode($environment);
        $encodedUnit = base64_encode($unit);
        $escapedImage = escapeshellarg($image);

        return <<<SCRIPT
set -eu

image={$escapedImage}
temporary_binary="\$(mktemp /tmp/coolify-sentinel.XXXXXX)"
container_id=''

cleanup() {
    if [ -n "\$container_id" ]; then
        docker container rm -f "\$container_id" >/dev/null 2>&1 || true
    fi
    rm -f "\$temporary_binary"
}
trap cleanup EXIT

docker pull {$escapedImage}
container_id="\$(docker create "\$image" /sentinel)"
docker cp "\$container_id:/sentinel" "\$temporary_binary"
test -s "\$temporary_binary"

install -d -m 0700 /etc/coolify /app/db
install -m 0755 "\$temporary_binary" /usr/local/bin/sentinel.new
printf '%s' '{$encodedEnvironment}' | base64 -d > /etc/coolify/sentinel.env.new
chmod 0600 /etc/coolify/sentinel.env.new
printf '%s' '{$encodedUnit}' | base64 -d > /etc/systemd/system/sentinel.service.new
chmod 0644 /etc/systemd/system/sentinel.service.new

mv -f /usr/local/bin/sentinel.new /usr/local/bin/sentinel
mv -f /etc/coolify/sentinel.env.new /etc/coolify/sentinel.env
mv -f /etc/systemd/system/sentinel.service.new /etc/systemd/system/sentinel.service

systemctl daemon-reload
systemctl enable sentinel.service
systemctl restart sentinel.service
systemctl is-active --quiet sentinel.service

attempt=0
until curl --fail --silent http://127.0.0.1:8888/api/health >/dev/null; do
    attempt=\$((attempt + 1))
    if [ "\$attempt" -ge 30 ]; then
        journalctl --unit sentinel.service --no-pager --lines 50
        exit 1
    fi
    sleep 1
done
SCRIPT;
    }

    public static function environmentFile(string $token, string $endpoint): string
    {
        return implode("\n", [
            'TOKEN='.$token,
            'PUSH_ENDPOINT='.$endpoint,
            'CONTROL_PLANE_ENABLED=true',
            'COLLECTOR_ENABLED=false',
            'STORAGE_ENABLED=false',
            'STORAGE_VOLUMES_ENABLED=false',
            'TRAFFIC_ENABLED=false',
            'SENTINEL_DEVELOPMENT=false',
        ])."\n";
    }

    public static function serviceUnit(): string
    {
        return <<<'UNIT'
[Unit]
Description=Coolify Sentinel
After=docker.service network-online.target
Wants=network-online.target

[Service]
Type=simple
EnvironmentFile=/etc/coolify/sentinel.env
ExecStart=/usr/local/bin/sentinel
WorkingDirectory=/app
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT;
    }

    private static function validateInputs(string $token, string $endpoint, string $image): void
    {
        if (! preg_match('/\A[a-zA-Z0-9._\-+=\/]+\z/', $token)) {
            throw new InvalidArgumentException('The Sentinel token contains invalid characters.');
        }
        if (filter_var($endpoint, FILTER_VALIDATE_URL) === false || ! preg_match('/\Ahttps?:\/\//', $endpoint)) {
            throw new InvalidArgumentException('The Sentinel endpoint must be an HTTP or HTTPS URL.');
        }
        if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:\/-]*\z/', $image)) {
            throw new InvalidArgumentException('The Sentinel host image is invalid.');
        }
    }
}
