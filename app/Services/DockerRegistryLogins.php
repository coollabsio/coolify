<?php

namespace App\Services;

use App\Helpers\SshMultiplexingHelper;
use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
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
     * Combine the logins of a server with the registries its resources need, one row per registry.
     *
     * @param  array<string, array{source: string, username: ?string}>  $loggedIn
     * @return array<int, array{registry: string, logged_in: bool, source: ?string, username: ?string, used_by: array<int, array{type: string, name: string, link: ?string}>}>
     */
    public static function rows(Server $server, array $loggedIn): array
    {
        $usedBy = self::usersOf($server)->groupBy('registry');

        return collect(array_keys($loggedIn))
            ->merge($usedBy->keys())
            ->unique()
            ->sort()
            ->map(fn (string $registry) => [
                'registry' => $registry,
                'logged_in' => isset($loggedIn[$registry]),
                'source' => $loggedIn[$registry]['source'] ?? null,
                'username' => $loggedIn[$registry]['username'] ?? null,
                'used_by' => ($usedBy[$registry] ?? collect())
                    ->map(fn (array $user) => Arr::except($user, 'registry'))
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Every resource that pulls or pushes a named image on this server, with the registry of that image.
     *
     * A build server builds and pushes for any application of its team that uses build servers, because
     * the deployment picks a random build server. A service is listed once per registry.
     *
     * @return Collection<int, array{registry: string, type: string, name: string, link: ?string}>
     */
    public static function usersOf(Server $server): Collection
    {
        $user = fn (string $image, string $type, string $name, ?string $link) => [
            'registry' => self::registryFromImage($image),
            'type' => $type,
            'name' => $name,
            'link' => $link,
        ];

        $applications = $server->applications()
            ->filter(fn (Application $application) => filled($application->docker_registry_image_name))
            ->map(fn (Application $application) => $user($application->docker_registry_image_name, 'Application', $application->name, $application->link()));

        $databases = $server->databases()
            ->filter(fn ($database) => filled($database->image))
            ->map(fn ($database) => $user($database->image, 'Database', $database->name, $database->link()));

        $services = $server->services()->with(['applications', 'databases'])->get()
            ->flatMap(fn (Service $service) => $service->applications->concat($service->databases)
                ->filter(fn ($container) => filled($container->image))
                ->map(fn ($container) => $user($container->image, 'Service', $service->name, $service->link())))
            ->unique(fn (array $entry) => $entry['registry'].'|'.$entry['link'].'|'.$entry['name']);

        $builds = $server->isBuildServer()
            ? self::applicationsBuiltOnBuildServers($server)
                ->map(fn (Application $application) => $user($application->docker_registry_image_name, 'Build', $application->name, $application->link()))
            : collect();

        return $applications->concat($databases)->concat($services)->concat($builds)->values();
    }

    /**
     * Applications of the build server's team that a deployment builds on a build server and pushes to a registry.
     *
     * @return Collection<int, Application>
     */
    private static function applicationsBuiltOnBuildServers(Server $buildServer): Collection
    {
        return Application::query()
            ->whereRelation('environment.project', 'team_id', $buildServer->team_id)
            ->whereNotNull('docker_registry_image_name')
            ->where('docker_registry_image_name', '!=', '')
            ->with(['settings', 'destination.server.settings'])
            ->orderBy('name')
            ->get()
            ->filter(function (Application $application) use ($buildServer) {
                $deploymentServer = $application->destination?->server;
                if ($deploymentServer === null || $deploymentServer->is($buildServer)) {
                    return false;
                }
                $mustBuildElsewhere = ! $deploymentServer->canBuildApplications()
                    && ! in_array($application->build_pack, ['dockerimage', 'dockercompose'], true);

                return $mustBuildElsewhere || (bool) data_get($application, 'settings.is_build_server_enabled');
            })
            ->values();
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
     * The SSH wrapper sends this script to the server's shell on stdin, and the shell builtin echo passes the
     * secret to docker on stdin, so it is not a process argument on the server. On the Coolify host it is still
     * in the command line of the local shell that runs ssh, because SshMultiplexingHelper::generateSshCommand()
     * embeds the script in a heredoc. It is kept out of SSH error logs and exception messages.
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

    /**
     * Check that the saved login still works. Without a username, docker login reuses the saved credentials
     * (also through credential helpers) and fails at once instead of prompting, because stdin is empty.
     */
    public static function checkLogin(Server $server, string $registry): void
    {
        self::run(
            $server,
            'docker --config "$HOME/.docker" login'.self::registryArgument($registry).' </dev/null',
            emptyErrorMessage: "There is no saved login for {$registry} on this server.",
        );
    }

    public static function logout(Server $server, string $registry): void
    {
        self::run($server, 'docker --config "$HOME/.docker" logout'.self::registryArgument($registry));
    }

    public static function audit(Server $server, string $action, string $registry, bool $succeeded = true, string $source = 'ui'): void
    {
        auditLog("{$source}.server.registry_{$action}", [
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
     * @param  string|null  $emptyErrorMessage  message when docker gives no useful error line
     */
    private static function run(Server $server, string $command, array $secrets = [], ?string $emptyErrorMessage = null): void
    {
        $commands = $server->isNonRoot() ? parseCommandsByLineForSudo(collect([$command]), $server) : [$command];
        $process = Process::timeout(60)->run(SshMultiplexingHelper::generateSshCommand($server, implode("\n", $commands)));

        if ($process->exitCode() === 0) {
            return;
        }

        $lines = collect(preg_split('/\R/', trim($process->errorOutput()."\n".$process->output())))
            ->map(fn (string $line) => trim(str_replace('Login did not succeed, error:', '', $line)))
            ->reject(fn (string $line) => $line === ''
                || str_starts_with($line, 'WARNING!')
                || str_contains($line, 'credential-stores')
                || str_starts_with($line, 'Info ->')
                || str_starts_with($line, 'Authenticating with existing credentials')
                || str_contains($line, 'cannot perform an interactive login')
                || str_starts_with($line, 'Control socket connect')
                || str_starts_with($line, 'ControlSocket ')
                || str_starts_with($line, 'Warning: Permanently added'));
        $dockerErrors = $lines->filter(fn (string $line) => str_starts_with($line, 'Error'));
        $error = ($dockerErrors->isNotEmpty() ? $dockerErrors : $lines)->implode(' ');
        $secrets = array_filter($secrets, fn (string $secret) => $secret !== '');
        $error = str_replace($secrets, '***', $error);

        throw new RuntimeException($error !== '' ? $error : ($emptyErrorMessage ?? "The command failed with exit code {$process->exitCode()}."));
    }
}
