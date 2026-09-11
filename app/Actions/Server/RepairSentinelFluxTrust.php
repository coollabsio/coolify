<?php

namespace App\Actions\Server;

use App\Actions\Sentinel\EnsureFluxCertificateAuthority;
use App\Models\Server;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

class RepairSentinelFluxTrust
{
    use AsAction;

    public function handle(Server $server): ?string
    {
        if (! isDev() || ! config('constants.sentinel.host_enabled', false)) {
            return null;
        }

        $authority = EnsureFluxCertificateAuthority::run();

        return instant_remote_process(
            [self::remoteCommand(self::repairScript($authority->certificate_pem, $authority->version))],
            $server,
            timeout: 600,
            disableMultiplexing: true,
        );
    }

    public static function repairScript(string $certificate, int $trustBundleVersion): string
    {
        $trustFiles = self::trustFileStagingScript($certificate, $trustBundleVersion);

        return <<<SCRIPT
set -eu
umask 077

environment_file=/etc/coolify/sentinel.env
install -d -m 0700 /etc/coolify
backup_directory="\$(mktemp -d /etc/coolify/.sentinel-repair.XXXXXX)"
completed=false
changed=false
was_active=false
was_enabled=false
had_environment=false
had_ca=false
had_ca_version=false

if systemctl is-active --quiet sentinel.service >/dev/null 2>&1; then was_active=true; fi
if systemctl is-enabled --quiet sentinel.service >/dev/null 2>&1; then was_enabled=true; fi

cleanup() {
    rm -f /etc/coolify/sentinel.env.new /etc/coolify/sentinel-flux-ca.pem.new /etc/coolify/sentinel-flux-ca.version.new
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
        restore_file /etc/coolify/sentinel.env "\$had_environment" "\$backup_directory/sentinel.env"
        restore_file /etc/coolify/sentinel-flux-ca.pem "\$had_ca" "\$backup_directory/sentinel-flux-ca.pem"
        restore_file /etc/coolify/sentinel-flux-ca.version "\$had_ca_version" "\$backup_directory/sentinel-flux-ca.version"
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

test -r "\$environment_file"
grep -v -E '^(FLUX_CA_PATH|FLUX_TRUST_BUNDLE_VERSION)=' "\$environment_file" > /etc/coolify/sentinel.env.new || grep -Eq '^(FLUX_CA_PATH|FLUX_TRUST_BUNDLE_VERSION)=' "\$environment_file"
printf '%s\\n' 'FLUX_CA_PATH=/etc/coolify/sentinel-flux-ca.pem' 'FLUX_TRUST_BUNDLE_VERSION={$trustBundleVersion}' >> /etc/coolify/sentinel.env.new
chmod 0600 /etc/coolify/sentinel.env.new
{$trustFiles}

if [ -e /etc/coolify/sentinel.env ]; then cp -a /etc/coolify/sentinel.env "\$backup_directory/sentinel.env"; had_environment=true; fi
if [ -e /etc/coolify/sentinel-flux-ca.pem ]; then cp -a /etc/coolify/sentinel-flux-ca.pem "\$backup_directory/sentinel-flux-ca.pem"; had_ca=true; fi
if [ -e /etc/coolify/sentinel-flux-ca.version ]; then cp -a /etc/coolify/sentinel-flux-ca.version "\$backup_directory/sentinel-flux-ca.version"; had_ca_version=true; fi

changed=true
mv -f /etc/coolify/sentinel-flux-ca.pem.new /etc/coolify/sentinel-flux-ca.pem
mv -f /etc/coolify/sentinel-flux-ca.version.new /etc/coolify/sentinel-flux-ca.version
mv -f /etc/coolify/sentinel.env.new /etc/coolify/sentinel.env
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

    public static function trustFileStagingScript(string $certificate, int $trustBundleVersion): string
    {
        if (openssl_x509_parse($certificate) === false) {
            throw new InvalidArgumentException('The Flux trust bundle is invalid.');
        }
        if ($trustBundleVersion < 1) {
            throw new InvalidArgumentException('The Flux trust bundle version is invalid.');
        }

        $encodedCertificate = escapeshellarg(base64_encode($certificate));
        $encodedVersion = escapeshellarg(base64_encode((string) $trustBundleVersion));

        return <<<SCRIPT
printf %s {$encodedCertificate} | base64 -d > /etc/coolify/sentinel-flux-ca.pem.new
chmod 0644 /etc/coolify/sentinel-flux-ca.pem.new
openssl x509 -in /etc/coolify/sentinel-flux-ca.pem.new -noout
printf %s {$encodedVersion} | base64 -d > /etc/coolify/sentinel-flux-ca.version.new
chmod 0644 /etc/coolify/sentinel-flux-ca.version.new
grep -Eq '^[1-9][0-9]*$' /etc/coolify/sentinel-flux-ca.version.new
SCRIPT;
    }

    public static function remoteCommand(string $script): string
    {
        return 'printf %s '.escapeshellarg(base64_encode($script)).' | base64 -d | bash';
    }
}
