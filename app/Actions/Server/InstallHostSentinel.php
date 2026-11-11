<?php

namespace App\Actions\Server;

use App\Models\Server;
use App\Models\ServerSetting;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

/**
 * Runs Sentinel as the systemd service `sentinel.service` on the host instead of the `coolify-sentinel` container.
 */
class InstallHostSentinel
{
    use AsAction;

    public const ENVIRONMENT_FILE = '/etc/coolify/sentinel.env';

    public const UNIT_FILE = '/etc/systemd/system/sentinel.service';

    public const BINARY = '/usr/local/bin/sentinel';

    /** The script exits with this code, before any change on the host, when the host cannot run the service. */
    public const UNSUPPORTED_EXIT_CODE = 3;

    /** On a failure, the script writes this prefix and the failed step to stderr, so the error that users see is short. */
    public const FAILURE_MARKER = 'COOLIFY_SENTINEL_FAILED: ';

    /**
     * @param  array<string, mixed>  $environment  the Sentinel environment that StartSentinel builds
     * @return bool false when the host cannot run the service (nothing changed on the host), true when the service is healthy
     *
     * @throws RuntimeException when the install failed; the script restored the previous host files and service state,
     *                          but the `coolify-sentinel` container may already be removed
     */
    public function handle(Server $server, array $environment, string $image): bool
    {
        $environment['DATA_DIR'] = devHostDockerPath($server, base_configuration_dir().'/sentinel');
        $script = self::installationScript($environment, $image);

        try {
            instant_remote_process([self::remoteCommand($script)], $server, throwError: true, timeout: 300);
        } catch (Throwable $e) {
            if ($e->getCode() === self::UNSUPPORTED_EXIT_CODE) {
                return false;
            }

            $step = preg_match('/^'.preg_quote(self::FAILURE_MARKER, '/').'(.+)$/m', $e->getMessage(), $match) ? trim($match[1]) : 'failed';

            // The full output (SSH messages, journal lines) stays in the previous exception for the log.
            throw new RuntimeException("Sentinel {$step}, so it could not be installed as a host service.", (int) $e->getCode(), $e);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $environment
     */
    public static function installationScript(array $environment, string $image): string
    {
        self::validateInputs($environment, $image);

        $encodedEnvironment = base64_encode(StartSentinel::envFileContent($environment));
        $encodedUnit = base64_encode(self::serviceUnit());
        $escapedImage = escapeshellarg($image);
        $escapedDataDirectory = escapeshellarg((string) $environment['DATA_DIR']);
        $unsupported = self::UNSUPPORTED_EXIT_CODE;
        $marker = self::FAILURE_MARKER;

        return <<<SCRIPT
set -eu

unsupported() {
    echo "Sentinel cannot run as a host service: \$1" >&2
    exit {$unsupported}
}
[ -d /run/systemd/system ] || unsupported 'systemd is not the init system'
command -v systemctl >/dev/null 2>&1 || unsupported 'systemctl is missing'
command -v curl >/dev/null 2>&1 || unsupported 'curl is missing'
command -v docker >/dev/null 2>&1 || unsupported 'docker is missing'
case "\$(uname -m)" in
    x86_64|amd64|aarch64|arm64) ;;
    *) unsupported "unsupported architecture \$(uname -m)" ;;
esac

umask 077
image={$escapedImage}
data_directory={$escapedDataDirectory}
temporary_binary="\$(mktemp /tmp/coolify-sentinel.XXXXXX)"
install -d -m 0700 /etc/coolify
backup_directory="\$(mktemp -d /etc/coolify/.sentinel-install.XXXXXX)"
container_id=''
completed=false
step='failed'
changed=false
was_active=false
was_enabled=false
had_binary=false
had_environment=false
had_unit=false

if systemctl is-active --quiet sentinel.service >/dev/null 2>&1; then was_active=true; fi
if systemctl is-enabled --quiet sentinel.service >/dev/null 2>&1; then was_enabled=true; fi

cleanup() {
    if [ -n "\$container_id" ]; then
        docker container rm -f "\$container_id" >/dev/null 2>&1 || true
    fi
    rm -f "\$temporary_binary"
    rm -f /usr/local/bin/sentinel.new /usr/local/bin/sentinel.previous.new /etc/coolify/sentinel.env.new /etc/systemd/system/sentinel.service.new /etc/coolify/sentinel.env.previous.new /etc/systemd/system/sentinel.service.previous.new
    rm -rf "\$backup_directory"
}

restore_file() {
    if [ "\$2" = true ]; then
        mv -f "\$3" "\$1" || true
    else
        rm -f "\$1"
    fi
}

rollback() {
    exit_code=\$?
    trap - EXIT
    if [ "\$exit_code" -ne 0 ]; then
        echo "{$marker}\$step" >&2
    fi
    if [ "\$changed" = true ] && [ "\$completed" != true ]; then
        systemctl stop sentinel.service || true
        if [ "\$was_enabled" != true ]; then
            systemctl disable sentinel.service || true
        fi
        restore_file /usr/local/bin/sentinel "\$had_binary" "\$backup_directory/sentinel"
        restore_file /etc/coolify/sentinel.env "\$had_environment" "\$backup_directory/sentinel.env"
        restore_file /etc/systemd/system/sentinel.service "\$had_unit" "\$backup_directory/sentinel.service"
        systemctl daemon-reload || true
        if [ "\$was_enabled" = true ]; then
            systemctl enable sentinel.service || true
        fi
        if [ "\$was_active" = true ]; then
            systemctl start sentinel.service || true
        fi
    fi
    cleanup
    exit "\$exit_code"
}
trap rollback EXIT

step='could not pull the image'
docker pull "\$image"
step='could not copy the binary out of the image'
container_id="\$(docker create "\$image" /sentinel)"
docker cp "\$container_id:/sentinel" "\$temporary_binary"
test -s "\$temporary_binary"

step='could not write the files'
install -m 0755 "\$temporary_binary" /usr/local/bin/sentinel.new
printf '%s' '{$encodedEnvironment}' | base64 -d > /etc/coolify/sentinel.env.new
chmod 0600 /etc/coolify/sentinel.env.new
printf '%s' '{$encodedUnit}' | base64 -d > /etc/systemd/system/sentinel.service.new
chmod 0644 /etc/systemd/system/sentinel.service.new

if [ -e /usr/local/bin/sentinel ]; then cp -a /usr/local/bin/sentinel "\$backup_directory/sentinel"; had_binary=true; fi
if [ -e /etc/coolify/sentinel.env ]; then cp -a /etc/coolify/sentinel.env "\$backup_directory/sentinel.env"; had_environment=true; fi
if [ -e /etc/systemd/system/sentinel.service ]; then cp -a /etc/systemd/system/sentinel.service "\$backup_directory/sentinel.service"; had_unit=true; fi

changed=true
# Only one process may use the SQLite files in the data directory.
docker rm -f coolify-sentinel >/dev/null 2>&1 || true
mv -f /usr/local/bin/sentinel.new /usr/local/bin/sentinel
mv -f /etc/coolify/sentinel.env.new /etc/coolify/sentinel.env
mv -f /etc/systemd/system/sentinel.service.new /etc/systemd/system/sentinel.service
mkdir -p "\$data_directory"
chmod 0700 "\$data_directory"

step='could not start sentinel.service'
systemctl daemon-reload
systemctl enable --quiet sentinel.service
systemctl restart sentinel.service
systemctl is-active --quiet sentinel.service

step='did not answer its health check on 127.0.0.1:8888 within 30 seconds'
attempt=0
until curl --fail --silent --connect-timeout 2 --max-time 5 http://127.0.0.1:8888/api/health >/dev/null; do
    attempt=\$((attempt + 1))
    if [ "\$attempt" -ge 30 ]; then
        journalctl --unit sentinel.service --no-pager --lines 50 >&2 || true
        exit 1
    fi
    sleep 1
done

if [ "\$had_binary" = true ]; then
    install -m 0755 "\$backup_directory/sentinel" /usr/local/bin/sentinel.previous.new
    mv -f /usr/local/bin/sentinel.previous.new /usr/local/bin/sentinel.previous
fi
if [ "\$had_environment" = true ]; then
    install -m 0600 "\$backup_directory/sentinel.env" /etc/coolify/sentinel.env.previous.new
    mv -f /etc/coolify/sentinel.env.previous.new /etc/coolify/sentinel.env.previous
else
    rm -f /etc/coolify/sentinel.env.previous
fi
if [ "\$had_unit" = true ]; then
    install -m 0644 "\$backup_directory/sentinel.service" /etc/systemd/system/sentinel.service.previous.new
    mv -f /etc/systemd/system/sentinel.service.previous.new /etc/systemd/system/sentinel.service.previous
else
    rm -f /etc/systemd/system/sentinel.service.previous
fi

completed=true
trap - EXIT
cleanup
SCRIPT;
    }

    /**
     * Sends a multi-line script as one line, so a non-root SSH user runs the whole script under one sudo:
     * parseCommandsByLineForSudo() turns a line with a pipe into `sudo bash -c '...'`.
     * The script is POSIX and runs with `sh`, so a host without bash (Alpine) reaches the preflight and is "not supported".
     */
    public static function remoteCommand(string $script): string
    {
        return 'printf %s '.escapeshellarg(base64_encode($script)).' | base64 -d | sh';
    }

    public static function serviceUnit(): string
    {
        return <<<'UNIT'
[Unit]
Description=Coolify Sentinel
After=network-online.target docker.service
Wants=network-online.target

[Service]
Type=simple
EnvironmentFile=/etc/coolify/sentinel.env
ExecStart=/usr/local/bin/sentinel
WorkingDirectory=/
KillMode=process
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT;
    }

    /**
     * systemd reads quotes and backslashes in an environment file as syntax, unlike Docker, so they are rejected.
     *
     * @param  array<string, mixed>  $environment
     */
    private static function validateInputs(array $environment, string $image): void
    {
        if (! ServerSetting::isValidSentinelToken(isset($environment['TOKEN']) ? (string) $environment['TOKEN'] : null)) {
            throw new InvalidArgumentException('The Sentinel token contains invalid characters.');
        }
        $endpoint = (string) ($environment['PUSH_ENDPOINT'] ?? '');
        if (filter_var($endpoint, FILTER_VALIDATE_URL) === false || ! preg_match('/\Ahttps?:\/\//', $endpoint)) {
            throw new InvalidArgumentException('The Sentinel endpoint must be an HTTP or HTTPS URL.');
        }
        if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:\/-]*(@sha256:[a-f0-9]{64})?\z/', $image)) {
            throw new InvalidArgumentException('The Sentinel host image is invalid.');
        }
        if (blank($environment['DATA_DIR'] ?? null) || ! str_starts_with((string) $environment['DATA_DIR'], '/')) {
            throw new InvalidArgumentException('The Sentinel data directory must be an absolute path.');
        }
        foreach ($environment as $key => $value) {
            if (! preg_match('/\A[A-Z_][A-Z0-9_]*\z/', (string) $key)) {
                throw new InvalidArgumentException("The Sentinel setting name {$key} is invalid.");
            }
            if (preg_match('/["\'\\\\]/', (string) $value)) {
                throw new InvalidArgumentException("The Sentinel setting {$key} must not contain quotes or backslashes.");
            }
        }
    }
}
