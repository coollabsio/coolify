<?php

namespace App\Actions\Proxy;

use App\Enums\ProxyTypes;
use App\Models\Server;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Backups of Traefik's acme.json, stored next to it as acme.json.backup-<UTC timestamp>-<random>.
 */
class ListTraefikAcmeBackups
{
    use AsAction;

    public const KEEP = 10;

    public const NAME_PATTERN = '/^acme\.json\.backup-(\d{8}T\d{6}Z)-[0-9a-f]{8}$/';

    private const SHELL_NAME_PATTERN = '^acme\.json\.backup-[0-9]{8}T[0-9]{6}Z-[0-9a-f]{8}$';

    /** @return array<int, array{name: string, created_at: string, size: int}> */
    public function handle(Server $server): array
    {
        if ($server->proxyType() !== ProxyTypes::TRAEFIK->value) {
            return [];
        }

        $pattern = escapeshellarg(self::directory($server)).'/acme.json.backup-*';
        $script = "for file in {$pattern}; do if [ -f \"\$file\" ]; then printf '%s %s\\n' \"\${file##*/}\" \"\$(wc -c < \"\$file\")\"; fi; done";
        $output = instant_remote_process(['sh -c '.escapeshellarg($script)], $server);

        return collect(preg_split('/\R/', (string) $output))
            ->map(function (string $line): ?array {
                if (! preg_match('/^(\S+) +(\d+)$/', trim($line), $matches)
                    || ! preg_match(self::NAME_PATTERN, $matches[1], $name)) {
                    return null;
                }

                return [
                    'name' => $matches[1],
                    'created_at' => CarbonImmutable::createFromFormat('Ymd\THis\Z', $name[1], 'UTC')->format('Y-m-d H:i:s').' UTC',
                    'size' => (int) $matches[2],
                ];
            })
            ->filter()
            ->sortByDesc('name')
            ->values()
            ->all();
    }

    /**
     * Resolve a backup name from the client against the backups that exist on the server.
     */
    public static function resolve(Server $server, string $name): string
    {
        if ($server->proxyType() !== ProxyTypes::TRAEFIK->value) {
            throw new RuntimeException('TLS certificates can only be managed for Traefik proxies.');
        }

        if (preg_match(self::NAME_PATTERN, $name) !== 1
            || ! collect(self::run($server))->contains('name', $name)) {
            throw new RuntimeException('The selected acme.json backup could not be found.');
        }

        return $name;
    }

    public static function directory(Server $server): string
    {
        return rtrim($server->proxyPath(), '/');
    }

    /**
     * Shell commands that copy the current acme.json, if any, to a new backup with mode 600.
     *
     * @return array{script: string, temporary_path: string}
     */
    public static function backupCommands(Server $server): array
    {
        $directory = self::directory($server);
        $suffix = bin2hex(random_bytes(4));
        $acmePath = escapeshellarg("{$directory}/acme.json");
        $temporaryPath = "{$directory}/.acme.json.backup-{$suffix}.tmp";
        $backupPath = escapeshellarg("{$directory}/acme.json.backup-".now()->utc()->format('Ymd\THis\Z')."-{$suffix}");
        $temporary = escapeshellarg($temporaryPath);

        return [
            'script' => "if [ -f {$acmePath} ]; then cp -- {$acmePath} {$temporary}; chmod 600 {$temporary}; mv -- {$temporary} {$backupPath}; fi",
            'temporary_path' => $temporaryPath,
        ];
    }

    /**
     * Shell commands that delete all but the newest backups.
     */
    public static function pruneCommands(Server $server): string
    {
        $directory = escapeshellarg(self::directory($server));

        return sprintf(
            'ls -1 -- %s | grep -E %s | sort -r | tail -n +%d | while IFS= read -r name; do rm -f -- %s/"$name"; done',
            $directory,
            escapeshellarg(self::SHELL_NAME_PATTERN),
            self::KEEP + 1,
            $directory,
        );
    }
}
