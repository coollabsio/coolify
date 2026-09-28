<?php

namespace App\Services;

use App\Helpers\SshMultiplexingHelper;
use App\Models\Server;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class DockerRegistryLogins
{
    public const DOCKER_HUB = 'docker.io';

    public const REGISTRY_PATTERN = '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:[0-9]{1,5})?$/';

    /**
     * Read the registries a server is logged in to from the Docker config that deployments mount.
     *
     * The config holds credentials, so only registry names and usernames leave this method.
     *
     * @return array<string, array{source: string, username: ?string}> registry => credential source (auths or credHelpers) and username
     */
    public static function forServer(Server $server): array
    {
        $config = instant_remote_process(['cat "$HOME/.docker/config.json" 2>/dev/null || true'], $server);

        return self::parseConfig($config);
    }

    /**
     * @return array<string, array{source: string, username: ?string}>
     */
    public static function parseConfig(?string $config): array
    {
        $decoded = json_decode((string) $config, true);
        if (! is_array($decoded)) {
            return [];
        }

        $registries = [];
        foreach (['auths', 'credHelpers'] as $source) {
            if (! is_array($decoded[$source] ?? null)) {
                continue;
            }
            foreach ($decoded[$source] as $key => $entry) {
                $registry = self::normalizeRegistry((string) $key);
                if (preg_match(self::REGISTRY_PATTERN, $registry) && ! isset($registries[$registry])) {
                    $registries[$registry] = [
                        'source' => $source,
                        'username' => $source === 'auths' && is_array($entry) ? self::usernameFromAuth($entry) : null,
                    ];
                }
            }
        }
        ksort($registries);

        return $registries;
    }

    /**
     * Docker stores "username:password" base64 encoded in "auth"; keep only the username part.
     *
     * @param  array<string, mixed>  $entry
     */
    private static function usernameFromAuth(array $entry): ?string
    {
        if (is_string($entry['username'] ?? null) && $entry['username'] !== '') {
            return $entry['username'];
        }
        $decoded = is_string($entry['auth'] ?? null) ? base64_decode($entry['auth'], true) : false;
        if ($decoded === false || ! str_contains($decoded, ':')) {
            return null;
        }
        $username = explode(':', $decoded, 2)[0];

        return $username !== '' && mb_check_encoding($username, 'UTF-8') ? $username : null;
    }

    /**
     * Turn a config key such as "https://index.docker.io/v1/" into a registry host.
     */
    public static function normalizeRegistry(string $key): string
    {
        $host = strtolower(trim($key));
        $host = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $host);
        $host = explode('/', $host, 2)[0];

        return in_array($host, ['index.docker.io', 'registry-1.docker.io', 'registry.hub.docker.com', 'docker.io'], true)
            ? self::DOCKER_HUB
            : $host;
    }

    /**
     * Return the registry host of an image reference, following Docker's own rule:
     * the first path part is a registry only if it has a dot or a port, or is "localhost".
     */
    public static function registryFromImage(string $image): string
    {
        $image = trim($image);
        if ($image === '' || ! str_contains($image, '/')) {
            return self::DOCKER_HUB;
        }

        $first = explode('/', $image, 2)[0];
        if (str_contains($first, '.') || str_contains($first, ':') || $first === 'localhost') {
            return self::normalizeRegistry($first);
        }

        return self::DOCKER_HUB;
    }

    /**
     * Run docker login on the server, writing to the same config file that deployments mount.
     *
     * The secret goes to docker through stdin via the shell builtin echo, so it is never a process argument
     * on the server, and it is kept out of SSH error logs and exception messages.
     */
    public static function login(Server $server, string $registry, string $username, string $password): void
    {
        $secret = base64_encode($password);

        self::run(
            $server,
            "echo '{$secret}' | base64 -d | docker --config \"\$HOME/.docker\" login --username ".escapeshellarg($username).' --password-stdin'.self::registryArgument($registry),
            [$password, $secret],
        );
    }

    public static function logout(Server $server, string $registry): void
    {
        self::run($server, 'docker --config "$HOME/.docker" logout'.self::registryArgument($registry));
    }

    public static function audit(Server $server, string $action, string $registry, bool $succeeded = true): void
    {
        auditLog("ui.server.registry_{$action}", [
            'server_uuid' => $server->uuid,
            'server_name' => $server->name,
            'team_id' => $server->team_id,
            'registry' => $registry,
            'outcome' => $succeeded ? 'success' : 'failed',
        ], $succeeded ? 'info' : 'warning');
    }

    /**
     * Docker keeps Docker Hub logins under its own default key, so let docker pick it.
     */
    private static function registryArgument(string $registry): string
    {
        return $registry === self::DOCKER_HUB ? '' : ' '.escapeshellarg($registry);
    }

    /**
     * @param  array<int, string>  $secrets  values that must not appear in the error message
     */
    private static function run(Server $server, string $command, array $secrets = []): void
    {
        $commands = $server->isNonRoot() ? parseCommandsByLineForSudo(collect([$command]), $server) : [$command];
        $process = Process::timeout(60)->run(SshMultiplexingHelper::generateSshCommand($server, implode("\n", $commands)));

        if ($process->exitCode() === 0) {
            return;
        }

        $lines = collect(preg_split('/\R/', trim($process->errorOutput())))
            ->map(fn (string $line) => trim($line))
            ->reject(fn (string $line) => $line === '' || str_starts_with($line, 'WARNING!') || str_contains($line, 'credential-stores'));
        $dockerErrors = $lines->filter(fn (string $line) => str_starts_with($line, 'Error'));
        $error = ($dockerErrors->isNotEmpty() ? $dockerErrors : $lines)->implode(' ');
        $secrets = array_filter($secrets, fn (string $secret) => $secret !== '');
        $error = str_replace($secrets, '***', $error);

        throw new RuntimeException($error !== '' ? $error : "The command failed with exit code {$process->exitCode()}.");
    }
}
