<?php

namespace App\Actions\Migration;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class InstallCoolifyOnHost
{
    use AsAction;

    public function handle(Server $target, bool $force = false): void
    {
        if (! $force && DetectIndependentCoolifyInstall::run($target)) {
            throw new RuntimeException('Target already has an independent Coolify install. Use a fresh VM or pass force.');
        }

        $cdn = rtrim((string) config('constants.coolify.cdn_url', 'https://cdn.coollabs.io'), '/');
        $installUrl = $cdn.'/coolify/install.sh';

        instant_remote_process(self::installCommands($installUrl), $target, timeout: 3600);

        $ready = instant_remote_process([
            'docker ps --format "{{.Names}}" | grep -E "^coolify-db$" || true',
        ], $target, false);

        if (! str_contains((string) $ready, 'coolify-db')) {
            throw new RuntimeException('Coolify install finished but coolify-db is not running on the target.');
        }

        EnsureCoolifyDataDirsTraversable::run($target);
    }

    /**
     * Commands must survive parseCommandsByLineForSudo() on non-root targets.
     * Avoid `|| (cmd && cmd)` — sudo rewriting turns that into invalid `|| sudo (`.
     *
     * @return list<string>
     */
    public static function installCommands(string $installUrl): array
    {
        return [
            // InstallPrerequisites-style: no parentheses, tolerate apt on RHEL / dnf on Debian.
            'command -v curl >/dev/null 2>&1 || apt-get update -y || true',
            'command -v curl >/dev/null 2>&1 || apt-get install -y curl || true',
            'command -v curl >/dev/null 2>&1 || dnf install -y curl || true',
            'command -v curl',
            'curl -fsSL '.escapeshellarg($installUrl).' -o /tmp/coolify-install.sh',
            'bash /tmp/coolify-install.sh',
            'rm -f /tmp/coolify-install.sh',
        ];
    }
}
