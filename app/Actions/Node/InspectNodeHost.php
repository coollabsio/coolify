<?php

namespace App\Actions\Node;

use App\Models\Node;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class InspectNodeHost
{
    use AsAction;

    /** @return array{hostname: string, os: string, arch: string, cpus: int, memory_bytes: int, package_manager: string, podman_installed: bool} */
    public function handle(Node $node): array
    {
        $command = <<<'SH'
set -eu
command -v systemctl >/dev/null
package_manager=''
for candidate in apt-get dnf yum; do
    if command -v "$candidate" >/dev/null; then package_manager="$candidate"; break; fi
done
test -n "$package_manager"
printf 'hostname=%s\n' "$(hostname)"
printf 'os=%s\n' "$(. /etc/os-release && printf '%s %s' "$NAME" "$VERSION_ID")"
printf 'arch=%s\n' "$(uname -m)"
printf 'cpus=%s\n' "$(getconf _NPROCESSORS_ONLN)"
printf 'memory_bytes=%s\n' "$(awk '/MemTotal/ { print $2 * 1024 }' /proc/meminfo | cut -d. -f1)"
printf 'package_manager=%s\n' "$package_manager"
printf 'podman_installed=%s\n' "$(command -v podman >/dev/null && echo 1 || echo 0)"
SH;
        $output = instant_remote_process([$command], $node, timeout: 30, disableMultiplexing: true);
        if (! is_string($output) || blank($output)) {
            throw new RuntimeException('Coolify could not inspect this server.');
        }

        $values = collect(preg_split('/\R/', trim($output)) ?: [])->mapWithKeys(function (string $line): array {
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');

            return [$key => $value];
        });
        foreach (['hostname', 'os', 'arch', 'cpus', 'memory_bytes', 'package_manager', 'podman_installed'] as $key) {
            if (blank($values->get($key))) {
                throw new RuntimeException('The server inspection returned incomplete information.');
            }
        }

        return [
            'hostname' => (string) $values['hostname'],
            'os' => (string) $values['os'],
            'arch' => (string) $values['arch'],
            'cpus' => (int) $values['cpus'],
            'memory_bytes' => (int) $values['memory_bytes'],
            'package_manager' => (string) $values['package_manager'],
            'podman_installed' => $values['podman_installed'] === '1',
        ];
    }
}
