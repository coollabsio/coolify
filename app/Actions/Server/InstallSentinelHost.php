<?php

namespace App\Actions\Server;

use App\Actions\Sentinel\EnsureFluxCertificateAuthority;
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
        $authority = EnsureFluxCertificateAuthority::run();
        $script = self::installationScript($token, $endpoint, $image, $authority->certificate_pem, $authority->version);

        return instant_remote_process(
            [self::remoteCommand($script)],
            $server,
            timeout: 600,
            disableMultiplexing: true,
        );
    }

    public static function installationScript(string $token, string $endpoint, string $image, string $certificate, int $trustBundleVersion): string
    {
        self::validateInputs($token, $endpoint, $image, $certificate, $trustBundleVersion);

        $environment = self::environmentFile($token, $endpoint, $trustBundleVersion);
        $unit = self::serviceUnit();
        $encodedEnvironment = base64_encode($environment);
        $encodedUnit = base64_encode($unit);
        $escapedImage = escapeshellarg($image);
        $trustFiles = RepairSentinelFluxTrust::trustFileStagingScript($certificate, $trustBundleVersion);

        return <<<SCRIPT
set -eu
umask 077

image={$escapedImage}
temporary_binary="\$(mktemp /tmp/coolify-sentinel.XXXXXX)"
install -d -m 0700 /etc/coolify /app/db
backup_directory="\$(mktemp -d /etc/coolify/.sentinel-install.XXXXXX)"
container_id=''
completed=false
changed=false
was_active=false
was_enabled=false
had_binary=false
had_environment=false
had_unit=false
had_ca=false
had_ca_version=false

if systemctl is-active --quiet sentinel.service >/dev/null 2>&1; then was_active=true; fi
if systemctl is-enabled --quiet sentinel.service >/dev/null 2>&1; then was_enabled=true; fi

cleanup() {
    if [ -n "\$container_id" ]; then
        docker container rm -f "\$container_id" >/dev/null 2>&1 || true
    fi
    rm -f "\$temporary_binary"
    rm -f /usr/local/bin/sentinel.new /etc/coolify/sentinel.env.new /etc/systemd/system/sentinel.service.new
    rm -f /etc/coolify/sentinel-flux-ca.pem.new /etc/coolify/sentinel-flux-ca.version.new
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
    if [ "\$changed" = true ] && [ "\$completed" != true ]; then
        systemctl stop sentinel.service || true
        if [ "\$was_enabled" != true ]; then
            systemctl disable sentinel.service || true
        fi
        restore_file /usr/local/bin/sentinel "\$had_binary" "\$backup_directory/sentinel"
        restore_file /etc/coolify/sentinel.env "\$had_environment" "\$backup_directory/sentinel.env"
        restore_file /etc/systemd/system/sentinel.service "\$had_unit" "\$backup_directory/sentinel.service"
        restore_file /etc/coolify/sentinel-flux-ca.pem "\$had_ca" "\$backup_directory/sentinel-flux-ca.pem"
        restore_file /etc/coolify/sentinel-flux-ca.version "\$had_ca_version" "\$backup_directory/sentinel-flux-ca.version"
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

docker pull {$escapedImage}
container_id="\$(docker create "\$image" /sentinel)"
docker cp "\$container_id:/sentinel" "\$temporary_binary"
test -s "\$temporary_binary"

install -m 0755 "\$temporary_binary" /usr/local/bin/sentinel.new
printf '%s' '{$encodedEnvironment}' | base64 -d > /etc/coolify/sentinel.env.new
chmod 0600 /etc/coolify/sentinel.env.new
printf '%s' '{$encodedUnit}' | base64 -d > /etc/systemd/system/sentinel.service.new
chmod 0644 /etc/systemd/system/sentinel.service.new
{$trustFiles}

if [ -e /usr/local/bin/sentinel ]; then cp -a /usr/local/bin/sentinel "\$backup_directory/sentinel"; had_binary=true; fi
if [ -e /etc/coolify/sentinel.env ]; then cp -a /etc/coolify/sentinel.env "\$backup_directory/sentinel.env"; had_environment=true; fi
if [ -e /etc/systemd/system/sentinel.service ]; then cp -a /etc/systemd/system/sentinel.service "\$backup_directory/sentinel.service"; had_unit=true; fi
if [ -e /etc/coolify/sentinel-flux-ca.pem ]; then cp -a /etc/coolify/sentinel-flux-ca.pem "\$backup_directory/sentinel-flux-ca.pem"; had_ca=true; fi
if [ -e /etc/coolify/sentinel-flux-ca.version ]; then cp -a /etc/coolify/sentinel-flux-ca.version "\$backup_directory/sentinel-flux-ca.version"; had_ca_version=true; fi

changed=true
mv -f /etc/coolify/sentinel-flux-ca.pem.new /etc/coolify/sentinel-flux-ca.pem
mv -f /etc/coolify/sentinel-flux-ca.version.new /etc/coolify/sentinel-flux-ca.version
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

completed=true
trap - EXIT
cleanup
SCRIPT;
    }

    public static function environmentFile(string $token, string $endpoint, int $trustBundleVersion): string
    {
        return implode("\n", [
            'TOKEN='.$token,
            'PUSH_ENDPOINT='.$endpoint,
            'FLUX_CA_PATH=/etc/coolify/sentinel-flux-ca.pem',
            'FLUX_TRUST_BUNDLE_VERSION='.$trustBundleVersion,
            'CONTROL_PLANE_ENABLED=true',
            'COLLECTOR_ENABLED=false',
            'STORAGE_ENABLED=false',
            'STORAGE_VOLUMES_ENABLED=false',
            'TRAFFIC_ENABLED=false',
            'SENTINEL_DEVELOPMENT=false',
        ])."\n";
    }

    public static function remoteCommand(string $script): string
    {
        return 'printf %s '.escapeshellarg(base64_encode($script)).' | base64 -d | bash';
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

    private static function validateInputs(string $token, string $endpoint, string $image, string $certificate, int $trustBundleVersion): void
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
        if (openssl_x509_parse($certificate) === false) {
            throw new InvalidArgumentException('The Flux trust bundle is invalid.');
        }
        if ($trustBundleVersion < 1) {
            throw new InvalidArgumentException('The Flux trust bundle version is invalid.');
        }
    }
}
