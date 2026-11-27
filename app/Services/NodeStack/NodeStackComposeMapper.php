<?php

namespace App\Services\NodeStack;

use App\Actions\Node\CreateClusterDockerImageWorkload;
use App\Actions\Node\CreateNodeVolume;
use App\Models\NodeVolume;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Maps a Compose file to the workloads of a stack (decision 0023). It keeps the meaning of the
 * v4 service parser: the same magic variables (`SERVICE_URL_*`, `SERVICE_FQDN_*`,
 * `SERVICE_PASSWORD_*`, …, generated with generateEnvValue()), and the same domains for the
 * service that declares a `SERVICE_URL_<NAME>_<PORT>` key. The v4 parser itself stays unchanged.
 *
 * Compose interpolates `${VAR}` from its `.env` file. A workload has no `.env`, so this class
 * resolves every `${VAR}`, `${VAR:-default}`, and `$$` itself with the variables of the stack.
 */
class NodeStackComposeMapper
{
    /** Sentinel limits of `workload.files.v1`. */
    public const MAX_FILES = 64;

    public const MAX_FILE_BYTES = 1024 * 1024;

    public const MAX_FILES_BYTES = 4 * 1024 * 1024;

    /** Sentinel limit of `workload.container_socket.v1`. */
    public const MAX_CONTAINER_SOCKET_PATHS = 4;

    /** The rule of Sentinel for `user` (`workload.user.v1`): a name or a uid, with an optional `:group` or `:gid`. */
    public const USER_PATTERN = '/\A[A-Za-z0-9_][A-Za-z0-9_.-]{0,31}(:[A-Za-z0-9_][A-Za-z0-9_.-]{0,31})?\z/';

    /**
     * Service keys of `workload.runtime_options.v1` (decision 0028). `container_name`, `hostname`,
     * `networks`, `platform`, and `logging` are ignored without a note: Nodes name, connect, and
     * log every container themselves.
     */
    public const RUNTIME_OPTION_KEYS = ['shm_size', 'ulimits', 'tmpfs', 'stop_grace_period', 'tty', 'stdin_open'];

    /** Sentinel limits of `workload.runtime_options.v1`: memory sizes, ulimits, tmpfs mounts, and the stop timeout. */
    public const MAX_RUNTIME_MEMORY_BYTES = 16 * 1024 * 1024 * 1024;

    public const MAX_ULIMITS = 16;

    public const MAX_TMPFS = 8;

    public const MAX_STOP_TIMEOUT_SECONDS = 600;

    /**
     * The version of the mapping that a stack stores in `compose_mapping_version`. Version 2 maps
     * `deploy.resources` (decision 0028). Before it, the services of a file got no resources.
     */
    public const MAPPING_VERSION = 2;

    public const ULIMIT_NAMES = ['nofile', 'nproc', 'memlock', 'core', 'stack', 'msgqueue', 'sigpending', 'rtprio'];

    /** Sentinel does not write a file, mount a socket, or mount a tmpfs under these paths. */
    private const FORBIDDEN_PATHS = ['/proc', '/sys', '/dev', '/run/secrets'];

    /** Magic commands that need the records of a v4 service. */
    private const UNSUPPORTED_COMMANDS = ['SUPABASEANON', 'SUPABASESERVICE'];

    /**
     * The generated variables of the stack: `SERVICE_NAME_*`, the URLs and FQDNs of each domain,
     * and every other `SERVICE_*` value that the file uses. An existing value is kept, so each
     * value is generated once for the stack.
     *
     * @param  array<string, mixed>  $yaml
     * @param  array<string, string>  $existing
     * @param  string  $domainBase  The host that generated domains end with, for example `1.2.3.4.sslip.io`.
     * @return array<string, string>
     */
    public static function variables(array $yaml, array $existing, string $stackUuid, string $domainBase, string $scheme): array
    {
        $variables = $existing;
        foreach (generateDockerComposeServiceName($yaml['services']) as $key => $value) {
            $variables[$key] ??= (string) $value;
        }
        $domain = fn (string $name): string => str($name)->lower()->replace('_', '-')->value().'-'.strtolower($stackUuid).'.'.$domainBase;

        foreach ($yaml['services'] as $service) {
            foreach (self::environment(is_array($service) ? $service : []) as $key => $value) {
                if (! str_starts_with($key, 'SERVICE_URL_') && ! str_starts_with($key, 'SERVICE_FQDN_')) {
                    continue;
                }
                $parsed = parseServiceEnvironmentVariable($key);
                $name = $parsed['has_port']
                    ? str($key)->after(str_starts_with($key, 'SERVICE_URL_') ? 'SERVICE_URL_' : 'SERVICE_FQDN_')->beforeLast('_')->value()
                    : str($key)->after(str_starts_with($key, 'SERVICE_URL_') ? 'SERVICE_URL_' : 'SERVICE_FQDN_')->value();
                $path = is_string($value) && str_starts_with($value, '/') && $value !== '/' ? $value : '';
                $host = $domain($name).$path;
                $variables["SERVICE_FQDN_{$name}"] ??= $host;
                $variables["SERVICE_URL_{$name}"] ??= "{$scheme}://{$host}";
                if ($parsed['has_port']) {
                    $variables["SERVICE_FQDN_{$name}_{$parsed['port']}"] ??= "{$host}:{$parsed['port']}";
                    $variables["SERVICE_URL_{$name}_{$parsed['port']}"] ??= "{$scheme}://{$host}:{$parsed['port']}";
                }
            }
        }

        foreach (self::references($yaml) as $key) {
            if (array_key_exists($key, $variables) || preg_match('/\ASERVICE_NAME_/', $key) === 1) {
                continue;
            }
            $command = parseCommandFromMagicEnvVariable($key)->value();
            if ($command === 'FQDN' || $command === 'URL') {
                $name = str($key)->after("SERVICE_{$command}_")->value();
                $variables["SERVICE_FQDN_{$name}"] ??= $domain($name);
                $variables["SERVICE_URL_{$name}"] ??= "{$scheme}://".$domain($name);

                continue;
            }
            if ($command === '' || in_array($command, self::UNSUPPORTED_COMMANDS, true)) {
                continue;
            }
            $value = generateEnvValue($command);
            if ($value !== null) {
                $variables[$key] = (string) $value;
            }
        }
        ksort($variables);

        return $variables;
    }

    /**
     * The workload of each service. A volume that two or more services mount, directly or with
     * `volumes_from`, is a stack volume (decision 0024): each of these services lists it in
     * `stack_volumes` with its own path and mode, keyed by `key`. Other volumes are in `volumes`.
     *
     * A service with `build:` (only in a Compose file from Git) has no image: `build` says what the
     * Node builder builds, with its args resolved like the environment.
     *
     * @param  array<string, mixed>  $yaml
     * @param  array<string, string>  $variables  See variables().
     * @param  ?string  $gitComposeDirectory  The directory of the Compose file in its Git repository; see NodeStackCompose::build().
     * @return list<array{service: string, image: ?string, build: ?array{context: string, dockerfile: string, args: array<string, string>, target: ?string}, command: list<string>, environment: array<string, string>, routes: list<array{scheme: string, host: string, port: int, path: string, noindex: bool}>, published_ports: list<array{protocol: string, host_port: int, container_port: int, source_cidrs: list<string>}>, health_check: ?array, restart_policy: string, volumes: list<array{name: string, path: string, key: string}>, stack_volumes: list<array{key: string, name: string, path: string, read_only: bool}>, files: list<array{path: string, content_base64: string, mode: string, directory: bool}>, container_socket_paths: list<string>, user: ?string, depends_on: array<string, string>, notes: list<string>}>
     */
    public static function services(array $yaml, array $variables, ?string $gitComposeDirectory = null, array $sharedKeys = []): array
    {
        // As the one `.env` file of a v4 service: a default in one service is the value in every service.
        $variables += self::defaults($yaml);
        $services = [];
        $fileBytes = 0;
        $fileCount = 0;
        // A service that others wait for until it exits with 0 runs once, even without `restart: "no"`.
        $oneTime = [];
        foreach ($yaml['services'] as $service) {
            foreach (self::dependencies(is_array($service) ? ($service['depends_on'] ?? []) : []) as $dependency => $condition) {
                if ($condition === 'service_completed_successfully') {
                    $oneTime[] = (string) $dependency;
                }
            }
        }
        $storage = self::stackStorage($yaml, $sharedKeys);
        foreach ($yaml['services'] as $name => $service) {
            $name = (string) $name;
            $service = is_array($service) ? $service : [];
            // Compose does not start a service with a profile unless the profile is selected.
            if (filled($service['profiles'] ?? null)) {
                continue;
            }
            ['resources' => $resources, 'notes' => $notes] = self::deployResources($name, $service['deploy'] ?? null, $variables);
            $entrypoint = self::words($service['entrypoint'] ?? null, $variables);
            $command = self::words($service['command'] ?? null, $variables);
            if ($entrypoint !== []) {
                // A workload has no entrypoint field: the entrypoint becomes the first words of the command.
                $command = [...$entrypoint, ...$command];
                $notes[] = 'The entrypoint runs as the command, after the entrypoint of the image.';
            }
            if (self::hasProxyLabels($service['labels'] ?? null)) {
                $notes[] = 'Proxy labels are ignored on Nodes. Use the domain settings of the service.';
            }

            $environment = [];
            foreach (self::environment($service) as $key => $value) {
                $environment[$key] = $value === null ? ($variables[$key] ?? '') : self::interpolate((string) $value, $variables);
            }
            ksort($environment);

            ['volumes' => $volumes, 'stack_volumes' => $stackVolumes, 'files' => $files, 'container_socket_paths' => $socketPaths, 'notes' => $mountNotes] = $storage[$name];
            $usedPaths = [...array_column($volumes, 'path'), ...array_column($stackVolumes, 'path'), ...array_column($files, 'path'), ...$socketPaths];
            $runtimeOptions = self::runtimeOptions($name, $service, $variables, $resources['memory_limit_bytes'] ?? null, $usedPaths);
            foreach ($files as $file) {
                $fileBytes += (int) (strlen($file['content_base64']) * 3 / 4);
                $fileCount++;
            }

            ['ports' => $publishedPorts, 'notes' => $portNotes] = self::publishedPorts($service, $variables);
            array_push($notes, ...$portNotes);

            $build = null;
            if ($gitComposeDirectory !== null && filled($service['build'] ?? null)) {
                $build = NodeStackCompose::build($service['build'], $gitComposeDirectory);
                array_push($notes, ...$build['notes']);
                if (filled($service['image'] ?? null)) {
                    $notes[] = 'The image name is ignored: Coolify builds the image and names it itself.';
                }
                $args = [];
                foreach (self::environment(['environment' => $build['args']]) as $key => $value) {
                    $args[$key] = $value === null ? ($variables[$key] ?? '') : self::interpolate((string) $value, $variables);
                }
                ksort($args);
                $build = ['context' => $build['context'], 'dockerfile' => $build['dockerfile'], 'args' => $args, 'target' => $build['target'] === null ? null : self::interpolate($build['target'], $variables)];
            }

            $services[] = [
                'service' => $name,
                'image' => $build !== null ? null : CreateClusterDockerImageWorkload::qualifiedImage(self::interpolate((string) $service['image'], $variables)),
                'build' => $build,
                'command' => $command,
                'environment' => $environment,
                'routes' => self::routes($service, $variables),
                'published_ports' => $publishedPorts,
                'health_check' => self::healthCheck($service['healthcheck'] ?? null, $variables),
                'restart_policy' => in_array($name, $oneTime, true) ? 'no' : self::restartPolicy($service['restart'] ?? null),
                'volumes' => $volumes,
                'stack_volumes' => $stackVolumes,
                'files' => $files,
                'container_socket_paths' => $socketPaths,
                'user' => self::user($name, $service['user'] ?? null, $variables),
                'resources' => $resources,
                'runtime_options' => $runtimeOptions,
                'depends_on' => self::dependencies($service['depends_on'] ?? []),
                'notes' => [...$notes, ...$mountNotes],
            ];
        }
        if ($fileCount > self::MAX_FILES || $fileBytes > self::MAX_FILES_BYTES) {
            throw new RuntimeException('A stack can have at most '.self::MAX_FILES.' file mounts and 4 MiB of file content.');
        }

        return $services;
    }

    /**
     * Whether the Compose labels have a Traefik or Caddy label (decision 0031). Nodes do not
     * translate them: the domain settings and the proxy options of the service replace them.
     */
    public static function hasProxyLabels(mixed $labels): bool
    {
        if (! is_array($labels)) {
            return false;
        }
        $keys = array_is_list($labels)
            ? array_map(fn (mixed $label): string => explode('=', (string) $label, 2)[0], $labels)
            : array_map(strval(...), array_keys($labels));

        return collect($keys)->contains(fn (string $key): bool => str_starts_with(strtolower(trim($key)), 'traefik.') || str_starts_with(strtolower(trim($key)), 'caddy'));
    }

    /**
     * The configuration of the first revision of a service: its command, restart policy, files,
     * the paths where Sentinel mounts the Podman socket of the server, the user, the CPU and
     * memory of `deploy.resources`, and the runtime options (decision 0028).
     *
     * @param  array{command: list<string>, restart_policy: string, files: list<array<string, mixed>>, container_socket_paths: list<string>, user: ?string, resources?: array<string, int|float>, runtime_options?: array<string, mixed>}  $service  One service of services().
     * @return array<string, mixed>
     */
    public static function revisionConfiguration(array $service): array
    {
        return array_filter([
            'command' => $service['command'],
            'restart_policy' => $service['restart_policy'],
            'files' => $service['files'],
            'container_socket_paths' => $service['container_socket_paths'] ?? [],
            'user' => $service['user'] ?? null,
            'resources' => $service['resources'] ?? [],
            'runtime_options' => $service['runtime_options'] ?? [],
        ], fn ($value): bool => $value !== [] && $value !== null);
    }

    /**
     * A Compose byte value (`128mb`, `1g`, `500M`, `1024`) in bytes, with 1024 as the unit. Null
     * when it is not a byte value.
     */
    public static function bytes(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (! is_string($value) || preg_match('/\A\s*(\d+(?:\.\d+)?)\s*([kmgt]?)b?\s*\z/i', $value, $match) !== 1) {
            return null;
        }
        $bytes = (float) $match[1] * (1024 ** match (strtolower($match[2])) {
            'k' => 1,
            'm' => 2,
            'g' => 3,
            't' => 4,
            default => 0,
        });

        return $bytes > PHP_INT_MAX ? null : (int) floor($bytes);
    }

    /**
     * The CPU and memory of `deploy.resources.limits` and `reservations`, as the fields of the
     * **Resource Limits** page. The other `deploy` keys are Swarm keys: a note says that they are
     * ignored.
     *
     * @param  array<string, string>  $variables
     * @return array{resources: array<string, int|float>, notes: list<string>}
     *
     * @throws RuntimeException When a value is not a number of CPUs or a byte size.
     */
    private static function deployResources(string $serviceName, mixed $deploy, array $variables): array
    {
        if (! is_array($deploy) || $deploy === []) {
            return ['resources' => [], 'notes' => []];
        }
        $notes = [];
        $ignored = array_diff(array_keys(array_filter($deploy, fn ($value): bool => filled($value))), ['resources']);
        $resources = is_array($deploy['resources'] ?? null) ? $deploy['resources'] : [];
        foreach (array_diff(array_keys(array_filter($resources, fn ($value): bool => filled($value))), ['limits', 'reservations']) as $key) {
            $ignored[] = "resources.{$key}";
        }
        $result = [];
        foreach (['limits' => ['cpu_limit', 'memory_limit_bytes'], 'reservations' => ['cpu_reservation', 'memory_reservation_bytes']] as $group => [$cpuField, $memoryField]) {
            $values = is_array($resources[$group] ?? null) ? $resources[$group] : [];
            foreach (array_diff(array_keys(array_filter($values, fn ($value): bool => filled($value))), ['cpus', 'memory']) as $key) {
                $ignored[] = "resources.{$group}.{$key}";
            }
            $cpus = $values['cpus'] ?? null;
            if (filled($cpus)) {
                $cpus = is_string($cpus) ? self::interpolate($cpus, $variables) : $cpus;
                if (! is_numeric($cpus) || (float) $cpus < 0.01 || (float) $cpus > 1024) {
                    throw new RuntimeException("The service {$serviceName} sets deploy.resources.{$group}.cpus to a value that is not 0.01 to 1024 CPUs.");
                }
                $result[$cpuField] = (float) $cpus;
            }
            $memory = $values['memory'] ?? null;
            if (filled($memory)) {
                $bytes = self::bytes(is_string($memory) ? self::interpolate($memory, $variables) : $memory);
                if ($bytes === null || $bytes < 4 * 1024 * 1024) {
                    throw new RuntimeException("The service {$serviceName} sets deploy.resources.{$group}.memory to a value that is not a size of at least 4 MiB, for example 512m.");
                }
                $result[$memoryField] = $bytes;
            }
        }
        if (isset($result['cpu_limit'], $result['cpu_reservation']) && $result['cpu_reservation'] > $result['cpu_limit']) {
            throw new RuntimeException("The service {$serviceName} reserves more CPUs than its limit in deploy.resources.");
        }
        if (isset($result['memory_limit_bytes'], $result['memory_reservation_bytes']) && $result['memory_reservation_bytes'] > $result['memory_limit_bytes']) {
            throw new RuntimeException("The service {$serviceName} reserves more memory than its limit in deploy.resources.");
        }
        if ($ignored !== []) {
            $notes[] = 'deploy.'.implode(', deploy.', $ignored).' '.(count($ignored) === 1 ? 'is' : 'are').' ignored on Nodes. Only the CPUs and memory of deploy.resources apply.';
        }

        return ['resources' => $result, 'notes' => $notes];
    }

    /**
     * The runtime options of a service (`workload.runtime_options.v1`, decision 0028) with the
     * rules of Sentinel. A key that is not set is left out, so the container keeps the Podman
     * default.
     *
     * @param  array<string, mixed>  $service
     * @param  array<string, string>  $variables
     * @param  list<string>  $usedPaths  The mount paths of the volumes, files, and sockets of the service.
     * @param  string|null  $subject  The start of an error message. Default: "The service <name>".
     * @return array{shm_size_bytes?: int, ulimits?: list<array{name: string, soft: int, hard: int}>, tmpfs?: list<array{path: string, size_bytes: int}>, stop_timeout_seconds?: int, tty?: true, stdin_open?: true}
     *
     * @throws RuntimeException When a value breaks a rule.
     */
    public static function runtimeOptions(string $serviceName, array $service, array $variables, ?int $memoryLimit, array $usedPaths, ?string $subject = null): array
    {
        $subject ??= "{$subject}";
        $value = fn (mixed $value): mixed => is_string($value) ? trim(self::interpolate($value, $variables)) : $value;
        $memorySize = function (string $key, int $bytes) use ($subject, $memoryLimit): void {
            if ($bytes < 1 || $bytes > self::MAX_RUNTIME_MEMORY_BYTES) {
                throw new RuntimeException("{$subject} sets {$key} to more than 16 GiB or to 0.");
            }
            if ($memoryLimit !== null && $bytes > $memoryLimit) {
                throw new RuntimeException("{$subject} sets {$key} larger than its memory limit.");
            }
        };
        $options = [];

        $shm = $value($service['shm_size'] ?? null);
        if (filled($shm)) {
            $bytes = self::bytes($shm);
            if ($bytes === null) {
                throw new RuntimeException("{$subject} sets shm_size: {$shm}. Use a size, for example 256m or 1g.");
            }
            $memorySize('shm_size', $bytes);
            $options['shm_size_bytes'] = $bytes;
        }

        $ulimits = $service['ulimits'] ?? null;
        if (filled($ulimits)) {
            if (! is_array($ulimits) || array_is_list($ulimits)) {
                throw new RuntimeException("{$subject} sets ulimits: to a value that is not a map of names.");
            }
            if (count($ulimits) > self::MAX_ULIMITS) {
                throw new RuntimeException("{$subject} sets more than ".self::MAX_ULIMITS.' ulimits.');
            }
            $limit = function (mixed $limit) use ($value): ?int {
                $limit = $value($limit);

                return is_int($limit) || (is_string($limit) && preg_match('/\A-?\d{1,19}\z/', $limit) === 1) ? (int) $limit : null;
            };
            foreach ($ulimits as $name => $setting) {
                $name = (string) $name;
                if (! in_array($name, self::ULIMIT_NAMES, true)) {
                    throw new RuntimeException("{$subject} sets the ulimit {$name}, which Nodes do not support. Use ".implode(', ', self::ULIMIT_NAMES).'.');
                }
                [$soft, $hard] = is_array($setting)
                    ? [$limit($setting['soft'] ?? null), $limit($setting['hard'] ?? null)]
                    : [$limit($setting), $limit($setting)];
                $valid = $soft !== null && $hard !== null && match (true) {
                    $soft === -1 && $hard === -1 => true,
                    $hard === -1 => $soft >= 0,
                    default => $soft >= 0 && $hard >= 0 && $soft <= $hard,
                };
                if (! $valid) {
                    throw new RuntimeException("{$subject} sets the ulimit {$name} to an invalid value. Use a number, or soft and hard with soft not above hard; -1 is unlimited.");
                }
                $options['ulimits'][] = ['name' => $name, 'soft' => $soft, 'hard' => $hard];
            }
        }

        $tmpfs = $service['tmpfs'] ?? null;
        if (filled($tmpfs)) {
            $entries = is_array($tmpfs) ? $tmpfs : [$tmpfs];
            if (count($entries) > self::MAX_TMPFS) {
                throw new RuntimeException("{$subject} sets more than ".self::MAX_TMPFS.' tmpfs mounts.');
            }
            $paths = [];
            foreach ($entries as $entry) {
                if (! is_string($entry)) {
                    throw new RuntimeException("{$subject} sets tmpfs: to a value that is not a path.");
                }
                [$path, $mountOptions] = array_pad(explode(':', $value($entry), 2), 2, '');
                $size = 0;
                foreach (array_filter(explode(',', $mountOptions)) as $option) {
                    $bytes = str_starts_with($option, 'size=') ? self::bytes(substr($option, 5)) : null;
                    if ($bytes === null) {
                        throw new RuntimeException("{$subject} sets the tmpfs option {$option} at {$path}. Nodes support only size=, for example /tmp:size=64m.");
                    }
                    $memorySize("the size of the tmpfs at {$path}", $bytes);
                    $size = $bytes;
                }
                if (! self::validMountPath($path)) {
                    throw new RuntimeException("{$subject} mounts a tmpfs at {$path}, which cannot be a mount target on Nodes.");
                }
                if (in_array($path, $paths, true) || in_array($path, $usedPaths, true)) {
                    throw new RuntimeException("{$subject} mounts a tmpfs and another mount at {$path}.");
                }
                $paths[] = $path;
                $options['tmpfs'][] = ['path' => $path, 'size_bytes' => $size];
            }
        }

        $grace = $value($service['stop_grace_period'] ?? null);
        if (filled($grace)) {
            $seconds = self::durationSeconds($grace);
            if ($seconds === null || $seconds < 1 || $seconds > self::MAX_STOP_TIMEOUT_SECONDS) {
                throw new RuntimeException("{$subject} sets stop_grace_period: {$grace}. Use 1s to 10m.");
            }
            $options['stop_timeout_seconds'] = $seconds;
        }

        foreach (['tty' => 'tty', 'stdin_open' => 'stdin_open'] as $key => $field) {
            $flag = $value($service[$key] ?? null);
            if ($flag === true || $flag === 'true') {
                $options[$field] = true;
            } elseif ($flag !== null && $flag !== false && $flag !== 'false' && $flag !== '') {
                throw new RuntimeException("{$subject} sets {$key}: to a value that is not true or false.");
            }
        }

        return $options;
    }

    /** The rules of Sentinel for a `files` path, without `:`: absolute, normalized, and not under a forbidden path. */
    private static function validMountPath(string $path): bool
    {
        return strlen($path) <= 4096
            && str_starts_with($path, '/')
            && $path !== '/'
            && ! str_ends_with($path, '/')
            && preg_match('/[,:\x00-\x1f\x7f]|\/\/|(^|\/)\.\.?(\/|$)/', $path) !== 1
            && ! collect(self::FORBIDDEN_PATHS)->contains(fn (string $prefix): bool => $path === $prefix || str_starts_with($path, $prefix.'/'));
    }

    /** A Compose duration (`1m30s`, `500ms`, `10`) in whole seconds, rounded up. Null when it is not a duration. */
    private static function durationSeconds(mixed $duration): ?int
    {
        if (is_int($duration) || (is_string($duration) && ctype_digit($duration))) {
            return (int) $duration;
        }
        if (! is_string($duration) || preg_match('/\A(\d+(?:\.\d+)?(h|ms|m|s|us|ns))+\z/', $duration) !== 1) {
            return null;
        }

        return self::seconds($duration, 0, 0, PHP_INT_MAX);
    }

    /**
     * The environment variables of a service: each variable of its environment, and each build
     * arg. A build arg with the name of an environment variable is that variable, also at build time.
     *
     * @param  array{environment: array<string, string>, build: ?array{args: array<string, string>}}  $service  One service of services().
     * @return array<string, array{value: string, is_runtime: bool, is_buildtime: bool}>
     */
    public static function environmentVariables(array $service): array
    {
        $buildArgs = $service['build']['args'] ?? [];
        $variables = [];
        foreach ([...$service['environment'], ...array_diff_key($buildArgs, $service['environment'])] as $key => $value) {
            $variables[(string) $key] = [
                'value' => (string) $value,
                'is_runtime' => array_key_exists($key, $service['environment']),
                'is_buildtime' => array_key_exists($key, $buildArgs),
            ];
        }

        return $variables;
    }

    /**
     * The mounts of the volumes that services share, one for each service and path: the first
     * mount at a path wins.
     *
     * @param  list<array{service: string, stack_volumes: list<array{key: string, name: string, path: string, read_only: bool}>}>  $services  See services().
     * @return list<array{service: string, key: string, name: string, path: string, read_only: bool}>
     */
    public static function stackMounts(array $services): array
    {
        $mounts = [];
        foreach ($services as $service) {
            $paths = [];
            foreach ($service['stack_volumes'] as $mount) {
                if (isset($paths[$mount['path']])) {
                    continue;
                }
                $paths[$mount['path']] = true;
                $mounts[] = ['service' => $service['service'], ...$mount];
            }
        }

        return $mounts;
    }

    /**
     * Resolves Compose interpolation: `$$` is `$`, `${VAR}` and `$VAR` are the value of VAR,
     * and `${VAR:-default}`, `${VAR-default}`, `${VAR:+other}`, `${VAR:?error}` work as in Compose.
     *
     * @param  array<string, string>  $variables
     */
    public static function interpolate(string $value, array $variables): string
    {
        $result = '';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $character = $value[$i];
            $next = $value[$i + 1] ?? '';
            if ($character !== '$' || $next === '') {
                $result .= $character;

                continue;
            }
            if ($next === '$') {
                $result .= '$';
                $i++;

                continue;
            }
            if ($next === '{') {
                $depth = 0;
                for ($end = $i + 1; $end < $length; $end++) {
                    $depth += match ($value[$end]) {
                        '{' => 1,
                        '}' => -1,
                        default => 0,
                    };
                    if ($depth === 0) {
                        break;
                    }
                }
                if ($end >= $length) {
                    $result .= substr($value, $i);

                    break;
                }
                $result .= self::expand(substr($value, $i + 2, $end - $i - 2), $variables);
                $i = $end;

                continue;
            }
            if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*/', substr($value, $i + 1), $match) === 1) {
                $result .= $variables[$match[0]] ?? '';
                $i += strlen($match[0]);

                continue;
            }
            $result .= '$';
        }

        return $result;
    }

    /** @param  array<string, string>  $variables */
    private static function expand(string $expression, array $variables): string
    {
        if (preg_match('/\A([A-Za-z_][A-Za-z0-9_]*)(?:(:?[-+?])(.*))?\z/s', $expression, $match) !== 1) {
            return '';
        }
        $value = $variables[$match[1]] ?? null;
        $operator = $match[2] ?? '';
        $operand = $match[3] ?? '';

        return match ($operator) {
            ':-' => $value === null || $value === '' ? self::interpolate($operand, $variables) : $value,
            '-' => $value ?? self::interpolate($operand, $variables),
            ':+' => $value !== null && $value !== '' ? self::interpolate($operand, $variables) : '',
            '+' => $value !== null ? self::interpolate($operand, $variables) : '',
            default => $value ?? '',
        };
    }

    /**
     * The Compose `user:` of a service, with its variables resolved, or null for the user of the
     * image. `1000` and `1000:1000` are a uid and a gid; `root` and `node:node` are names.
     *
     * @param  array<string, string>  $variables
     *
     * @throws RuntimeException When the value is not a name or a uid with an optional group.
     */
    private static function user(string $serviceName, mixed $user, array $variables): ?string
    {
        if ($user === null || $user === '') {
            return null;
        }
        if (! is_string($user) && ! is_int($user)) {
            throw new RuntimeException("The service {$serviceName} sets user: to a value that is not a name or a uid.");
        }
        $value = trim(self::interpolate((string) $user, $variables));
        if ($value === '') {
            return null;
        }
        if (preg_match(self::USER_PATTERN, $value) !== 1) {
            throw new RuntimeException("The service {$serviceName} sets user: {$value}. Use a user name or a uid, with an optional group name or gid, for example 1000:1000.");
        }

        return $value;
    }

    /**
     * The default of each variable that the file gives one, with `${VAR:-default}` or
     * `${VAR-default}`, outside `content:` mounts. The first default in the file wins. v4 writes
     * these defaults to the `.env` file of the service, which every container of the service
     * reads, so `${VAR}` without a default in another service gets the same value. A default with
     * a variable in it and a `SERVICE_*` variable are left out.
     *
     * @param  array<string, mixed>  $yaml
     * @return array<string, string>
     */
    private static function defaults(array $yaml): array
    {
        $defaults = [];
        foreach ($yaml['services'] as $service) {
            $service = is_array($service) ? $service : [];
            foreach ((array) ($service['volumes'] ?? []) as $index => $volume) {
                if (is_array($volume)) {
                    unset($service['volumes'][$index]['content']);
                }
            }
            array_walk_recursive($service, function ($value) use (&$defaults): void {
                if (! is_string($value)) {
                    return;
                }
                preg_match_all('/(?<!\$)\$\{([A-Za-z_][A-Za-z0-9_]*):?-([^${}]*)\}/', $value, $matches, PREG_SET_ORDER);
                foreach ($matches as [, $name, $default]) {
                    if (! str_starts_with($name, 'SERVICE_')) {
                        $defaults[$name] ??= $default;
                    }
                }
            });
        }

        return $defaults;
    }

    /**
     * Every `SERVICE_*` variable that a service references, outside `content:` mounts.
     *
     * @param  array<string, mixed>  $yaml
     * @return list<string>
     */
    private static function references(array $yaml): array
    {
        $references = [];
        foreach ($yaml['services'] as $service) {
            $service = is_array($service) ? $service : [];
            foreach ((array) ($service['volumes'] ?? []) as $index => $volume) {
                if (is_array($volume)) {
                    unset($service['volumes'][$index]['content']);
                }
            }
            array_walk_recursive($service, function ($value, $key) use (&$references): void {
                foreach ([(string) $key, is_string($value) ? $value : ''] as $text) {
                    // `$$` is a literal dollar sign for the shell of the container.
                    preg_match_all('/(?<!\$)\$\{?(SERVICE_[A-Za-z0-9_]+)/', str_replace('$$', '', $text), $matches);
                    array_push($references, ...$matches[1]);
                }
            });
        }

        return array_values(array_unique($references));
    }

    /**
     * The environment of a service as a map. A key without a value is null: Compose passes the
     * variable of the same name.
     *
     * @param  array<string, mixed>  $service
     * @return array<string, ?string>
     */
    private static function environment(array $service): array
    {
        $environment = [];
        $raw = $service['environment'] ?? [];
        if (! is_array($raw)) {
            return [];
        }
        foreach ($raw as $key => $value) {
            if (is_int($key)) {
                [$key, $value] = array_pad(explode('=', (string) $value, 2), 2, null);
            }
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }
            $environment[$key] = match (true) {
                $value === null => null,
                is_bool($value) => $value ? 'true' : 'false',
                default => (string) $value,
            };
        }

        return $environment;
    }

    /**
     * One domain for each `SERVICE_URL_<NAME>_<PORT>` or `SERVICE_FQDN_<NAME>_<PORT>` key of
     * the service, like the v4 parser sets the FQDN of the service that declares the key. A key
     * without a port routes to port 80. The host is the generated `SERVICE_FQDN_<NAME>`.
     *
     * @param  array<string, mixed>  $service
     * @param  array<string, string>  $variables
     * @return list<array{scheme: string, host: string, port: int, path: string, noindex: bool}>
     */
    private static function routes(array $service, array $variables): array
    {
        $routes = [];
        foreach (self::environment($service) as $key => $value) {
            $prefix = str_starts_with($key, 'SERVICE_URL_') ? 'SERVICE_URL_' : (str_starts_with($key, 'SERVICE_FQDN_') ? 'SERVICE_FQDN_' : null);
            if ($prefix === null) {
                continue;
            }
            $parsed = parseServiceEnvironmentVariable($key);
            $name = $parsed['has_port'] ? str($key)->after($prefix)->beforeLast('_')->value() : str($key)->after($prefix)->value();
            $host = str($variables["SERVICE_FQDN_{$name}"] ?? '')->before('/')->value();
            if ($host === '') {
                continue;
            }
            $path = is_string($value) && str_starts_with($value, '/') && $value !== '/' ? rtrim($value, '/') : '';
            $routes[$host.$path] = [
                'scheme' => str($variables["SERVICE_URL_{$name}"] ?? 'http://')->before('://')->value(),
                'host' => $host,
                'port' => (int) ($parsed['port'] ?? 80),
                'path' => $path,
                'noindex' => false,
            ];
        }

        return array_values($routes);
    }

    /**
     * The `ports:` of a service as published ports (decision 0016): `HOST:CONTAINER[/PROTOCOL]`, or
     * the long syntax with `target`, `published`, and `protocol`. An entry with a host address,
     * without a host port, or with a range is not published, with a note. `0.0.0.0` is every
     * address, as without a host address. Sorted by protocol and host port.
     *
     * @param  array<string, mixed>  $service
     * @param  array<string, string>  $variables
     * @return array{ports: list<array{protocol: string, host_port: int, container_port: int, source_cidrs: list<string>}>, notes: list<string>}
     */
    private static function publishedPorts(array $service, array $variables): array
    {
        $ports = [];
        $notes = [];
        foreach ((array) ($service['ports'] ?? []) as $entry) {
            $value = fn (mixed $part): string => trim(self::interpolate((string) $part, $variables));
            if (is_array($entry)) {
                $label = 'target '.$value($entry['target'] ?? '');
                $hostIp = $value($entry['host_ip'] ?? '');
                $hostPort = $value($entry['published'] ?? '');
                $containerPort = $value($entry['target'] ?? '');
                $protocol = strtolower($value($entry['protocol'] ?? 'tcp'));
            } else {
                $label = $value($entry);
                [$mapping, $protocol] = str_contains($label, '/') ? explode('/', $label, 2) : [$label, 'tcp'];
                $protocol = strtolower($protocol);
                $parts = str_starts_with($mapping, '[') ? [substr($mapping, 0, (int) strpos($mapping, ']') + 1), '', ''] : explode(':', $mapping);
                [$hostIp, $hostPort, $containerPort] = match (count($parts)) {
                    1 => ['', '', $parts[0]],
                    2 => ['', $parts[0], $parts[1]],
                    default => [implode(':', array_slice($parts, 0, -2)), $parts[count($parts) - 2], $parts[count($parts) - 1]],
                };
            }
            $reason = match (true) {
                ! in_array($hostIp, ['', '0.0.0.0'], true) => "it binds the host address {$hostIp}, and every server of the cluster publishes on all its addresses",
                str_contains($hostPort.$containerPort, '-') => 'it is a port range',
                $hostPort === '' => 'it has no host port',
                ! in_array($protocol, ['tcp', 'udp'], true) => "the protocol {$protocol} is not TCP or UDP",
                ! self::isPort($hostPort) || ! self::isPort($containerPort) => 'it is not a port between 1 and 65535',
                default => null,
            };
            if ($reason !== null) {
                $notes[] = "The port {$label} is not published: {$reason}.";

                continue;
            }
            $ports["{$protocol}/{$hostPort}/{$containerPort}"] = [
                'protocol' => $protocol,
                'host_port' => (int) $hostPort,
                'container_port' => (int) $containerPort,
                'source_cidrs' => [],
            ];
        }
        $ports = array_values($ports);
        usort($ports, fn (array $a, array $b): int => [$a['protocol'], $a['host_port']] <=> [$b['protocol'], $b['host_port']]);

        return ['ports' => $ports, 'notes' => $notes];
    }

    private static function isPort(string $value): bool
    {
        return ctype_digit($value) && (int) $value >= 1 && (int) $value <= 65535;
    }

    /**
     * The storage of each service that the stack runs. A volume that two or more of them mount
     * becomes a stack volume. `volumes_from: [other]` mounts every mount of `other` at the same
     * path, read-only with `:ro`, as Compose does.
     *
     * @param  array<string, mixed>  $yaml
     * @return array<string, array{volumes: list<array{name: string, path: string, key: string}>, stack_volumes: list<array{key: string, name: string, path: string, read_only: bool}>, files: list<array{path: string, content_base64: string, mode: string, directory: bool}>, notes: list<string>}>
     */
    /**
     * @param  array<string, true>  $sharedKeys  Keys that stay a stack volume also when one service mounts them: the data is already in a stack volume.
     */
    private static function stackStorage(array $yaml, array $sharedKeys = []): array
    {
        $own = [];
        foreach ($yaml['services'] as $name => $service) {
            $service = is_array($service) ? $service : [];
            $own[(string) $name] = self::storage((string) $name, $service);
        }
        $resolved = [];
        $resolve = function (string $name, array $path) use (&$resolve, &$resolved, $own, $yaml): array {
            if (isset($resolved[$name])) {
                return $resolved[$name];
            }
            if (in_array($name, $path, true)) {
                throw new RuntimeException('The services '.implode(' -> ', [...$path, $name]).' mount the volumes of each other in a cycle (volumes_from).');
            }
            $storage = $own[$name];
            $paths = array_flip(array_column($storage['mounts'], 'path'));
            $filePaths = array_flip(array_column($storage['files'], 'path'));
            foreach (NodeStackCompose::volumesFrom(is_array($yaml['services'][$name] ?? null) ? $yaml['services'][$name] : []) as $entry) {
                if (! isset($own[$entry['service']])) {
                    throw new RuntimeException("The service {$name} mounts the volumes of {$entry['service']} (volumes_from), which is not a service of this stack.");
                }
                $from = $resolve($entry['service'], [...$path, $name]);
                foreach ($from['mounts'] as $mount) {
                    if (! isset($paths[$mount['path']])) {
                        $paths[$mount['path']] = true;
                        $storage['mounts'][] = [...$mount, 'read_only' => $mount['read_only'] || $entry['read_only']];
                    }
                }
                foreach ($from['files'] as $file) {
                    if (! isset($filePaths[$file['path']])) {
                        $filePaths[$file['path']] = true;
                        $storage['files'][] = $file;
                    }
                }
                foreach ($from['sockets'] as $socket) {
                    if (! in_array($socket['path'], array_column($storage['sockets'], 'path'), true)) {
                        $storage['sockets'][] = $socket;
                    }
                }
            }

            return $resolved[$name] = $storage;
        };

        $running = array_keys(array_filter($yaml['services'], fn ($service): bool => blank(is_array($service) ? ($service['profiles'] ?? null) : null)));
        $users = [];
        foreach ($running as $name) {
            foreach ($resolve((string) $name, [])['mounts'] as $mount) {
                $users[$mount['key']][(string) $name] = true;
            }
        }

        $result = [];
        foreach ($running as $name) {
            $name = (string) $name;
            $storage = $resolved[$name];
            $volumes = [];
            $stackVolumes = [];
            $notes = $storage['notes'];
            foreach ($storage['mounts'] as $mount) {
                if (count($users[$mount['key']]) > 1 || isset($sharedKeys[$mount['key']])) {
                    $stackVolumes[] = $mount;

                    continue;
                }
                $volumes[] = ['name' => $mount['name'], 'path' => $mount['path'], 'key' => $mount['key']];
                if ($mount['read_only']) {
                    $notes[] = "The volume at {$mount['path']} is mounted read-write: only a volume that services share can be read-only on Nodes.";
                }
            }
            if (count($volumes) + count($stackVolumes) > NodeVolume::MAX_PER_WORKLOAD) {
                throw new RuntimeException("The service {$name} mounts more than ".NodeVolume::MAX_PER_WORKLOAD.' volumes.');
            }
            $socketPaths = self::containerSocketPaths($name, $storage);
            foreach ($storage['sockets'] as $socket) {
                if ($socket['read_only']) {
                    $notes[] = "The Docker socket at {$socket['path']} is mounted read-write: Nodes mount the socket only read-write.";
                }
            }
            $result[$name] = ['volumes' => $volumes, 'stack_volumes' => $stackVolumes, 'files' => $storage['files'], 'container_socket_paths' => $socketPaths, 'notes' => array_values(array_unique($notes))];
        }

        return $result;
    }

    /**
     * The paths where Sentinel mounts the Podman socket of the server in the container of a
     * service, with the rules of `workload.container_socket.v1`. Sentinel always mounts the
     * socket read-write.
     *
     * @param  array{mounts: list<array{path: string}>, files: list<array{path: string}>, sockets: list<array{path: string, read_only: bool}>}  $storage
     * @return list<string>
     */
    private static function containerSocketPaths(string $serviceName, array $storage): array
    {
        $paths = array_values(array_unique(array_column($storage['sockets'], 'path')));
        if (count($paths) > self::MAX_CONTAINER_SOCKET_PATHS) {
            throw new RuntimeException("The service {$serviceName} mounts the Docker socket more than ".self::MAX_CONTAINER_SOCKET_PATHS.' times.');
        }
        $used = array_flip([...array_column($storage['mounts'], 'path'), ...array_column($storage['files'], 'path')]);
        foreach ($paths as $path) {
            $forbidden = collect(self::FORBIDDEN_PATHS)->contains(fn (string $prefix): bool => $path === $prefix || str_starts_with($path, $prefix.'/'));
            if ($forbidden || str_contains($path, ':')) {
                throw new RuntimeException("The service {$serviceName} mounts the Docker socket at {$path}, which cannot be a mount target on Nodes.");
            }
            if (isset($used[$path])) {
                throw new RuntimeException("The service {$serviceName} mounts the Docker socket and another volume or file at {$path}.");
            }
        }

        return $paths;
    }

    /**
     * The mounts of one service: named volumes stay named volumes, a relative bind (`./data`) is a
     * named volume of the stack, and a `content:` mount is a file that Sentinel writes (decision
     * 0023). `key` identifies the data, so the services that mount the same key share it. A mount
     * of the Docker or Podman socket is in `sockets`: Sentinel mounts the Podman socket of the
     * server there.
     *
     * @param  array<string, mixed>  $service
     * @return array{mounts: list<array{key: string, name: string, path: string, read_only: bool}>, files: list<array{path: string, content_base64: string, mode: string, directory: bool}>, sockets: list<array{path: string, read_only: bool}>, notes: list<string>}
     */
    private static function storage(string $serviceName, array $service): array
    {
        $mounts = [];
        $files = [];
        $sockets = [];
        $notes = [];
        foreach (NodeStackCompose::mounts($service) as $mount) {
            $path = CreateNodeVolume::normalizeMountPath($mount['target']);
            if ($path === null) {
                throw new RuntimeException("The service {$serviceName} mounts the path {$mount['target']}, which cannot be a mount target.");
            }
            switch ($mount['kind']) {
                case 'named':
                case 'relative':
                    if ($mount['source'] === null) {
                        $name = $serviceName.'-'.Str::slug(basename($path));
                        $key = "anonymous:{$serviceName}:{$path}";
                    } else {
                        $source = $mount['kind'] === 'relative' ? trim(preg_replace('#^(\./)+#', '', (string) $mount['source']), '/') : (string) $mount['source'];
                        $name = trim(Str::slug(str_replace('/', '-', ltrim($source, './'))), '-');
                        $key = "{$mount['kind']}:{$source}";
                    }
                    $mounts[] = ['key' => $key, 'name' => $name !== '' ? $name : $serviceName.'-data', 'path' => $path, 'read_only' => (bool) $mount['read_only']];
                    break;
                case 'content':
                    $content = (string) $mount['content'];
                    if (strlen($content) > self::MAX_FILE_BYTES) {
                        throw new RuntimeException("The file {$path} of the service {$serviceName} is larger than 1 MiB.");
                    }
                    $files[] = ['path' => $path, 'content_base64' => base64_encode($content), 'mode' => '0644', 'directory' => false];
                    break;
                case 'tmpfs':
                    $notes[] = "The tmpfs mount at {$path} is not supported on Nodes yet and is ignored.";
                    break;
                case 'socket':
                    $sockets[] = ['path' => $path, 'read_only' => (bool) $mount['read_only']];
                    break;
                default:
                    throw new RuntimeException("The service {$serviceName} mounts a host path, which Nodes do not allow.");
            }
        }

        return ['mounts' => $mounts, 'files' => $files, 'sockets' => $sockets, 'notes' => $notes];
    }

    /**
     * The Compose health check as a workload health check. `NONE` and `disable: true` turn it off.
     *
     * @param  array<string, string>  $variables
     * @return array{command: string, description: string, interval_seconds: int, timeout_seconds: int, retries: int, start_period_seconds: int}|null
     */
    private static function healthCheck(mixed $check, array $variables): ?array
    {
        if (! is_array($check) || ($check['disable'] ?? false) === true || ! array_key_exists('test', $check)) {
            return null;
        }
        $test = $check['test'];
        if (is_array($test)) {
            $form = strtoupper((string) array_shift($test));
            $command = match ($form) {
                'CMD-SHELL' => implode(' ', array_map('strval', $test)),
                // The exec form runs without a shell, so images without /bin/sh keep their health check.
                'CMD' => json_encode(array_map(fn ($argument): string => self::interpolate((string) $argument, $variables), array_values($test)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                default => null,
            };
            if ($form === 'CMD-SHELL' && $command !== null) {
                $command = self::interpolate($command, $variables);
            }
        } else {
            $command = self::interpolate((string) $test, $variables);
        }
        $command = trim((string) preg_replace('/\s*[\r\n]+\s*/', ' ', (string) $command));
        if ($command === '' || strlen($command) > 4096) {
            return null;
        }

        return [
            'command' => $command,
            'description' => mb_substr($command, 0, 4096),
            'interval_seconds' => self::seconds($check['interval'] ?? null, 30, 1, 3600),
            'timeout_seconds' => self::seconds($check['timeout'] ?? null, 30, 1, 3600),
            'retries' => min(max((int) ($check['retries'] ?? 3), 1), 100),
            'start_period_seconds' => self::seconds($check['start_period'] ?? null, 0, 0, 3600),
        ];
    }

    /** A Compose duration (`1m30s`, `500ms`, `10`) in whole seconds within the bounds. */
    private static function seconds(mixed $duration, int $default, int $min, int $max): int
    {
        if ($duration === null || $duration === '') {
            return $default;
        }
        if (is_numeric($duration)) {
            return min(max((int) $duration, $min), $max);
        }
        preg_match_all('/(\d+(?:\.\d+)?)(h|ms|m|s|us|ns)/', (string) $duration, $parts, PREG_SET_ORDER);
        $seconds = 0.0;
        foreach ($parts as [, $amount, $unit]) {
            $seconds += (float) $amount * match ($unit) {
                'h' => 3600,
                'm' => 60,
                's' => 1,
                'ms' => 0.001,
                default => 0,
            };
        }

        return min(max((int) ceil($seconds), $min), $max);
    }

    /** `restart: "no"` stays `no`: a one-time container (decision 0023). The default is `unless-stopped`. */
    private static function restartPolicy(mixed $restart): string
    {
        $restart = is_bool($restart) ? ($restart ? 'always' : 'no') : strtolower(trim((string) $restart));
        $restart = str_starts_with($restart, 'on-failure') ? 'on-failure' : $restart;

        return in_array($restart, ['no', 'always', 'on-failure', 'unless-stopped'], true) ? $restart : 'unless-stopped';
    }

    /**
     * `depends_on` as service => condition. The list form means `service_started`.
     *
     * @return array<string, string>
     */
    public static function dependencies(mixed $dependsOn): array
    {
        $dependencies = [];
        foreach ((array) $dependsOn as $key => $value) {
            if (is_int($key)) {
                $dependencies[(string) $value] = 'service_started';
            } else {
                $condition = is_array($value) ? (string) ($value['condition'] ?? 'service_started') : 'service_started';
                $dependencies[(string) $key] = in_array($condition, ['service_started', 'service_healthy', 'service_completed_successfully'], true) ? $condition : 'service_started';
            }
        }

        return $dependencies;
    }

    /**
     * The words of a Compose `command` or `entrypoint`: a list as written, or a string split like a shell does.
     *
     * @param  array<string, string>  $variables
     * @return list<string>
     */
    private static function words(mixed $value, array $variables): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }
        if (is_array($value)) {
            return array_values(array_map(fn ($word): string => self::interpolate((string) $word, $variables), $value));
        }

        return array_map(fn (string $word): string => self::interpolate($word, $variables), self::splitWords((string) $value));
    }

    /** @return list<string> */
    private static function splitWords(string $value): array
    {
        $words = [];
        $word = '';
        $quote = null;
        $hasWord = false;
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $character = $value[$i];
            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                } elseif ($character === '\\' && $quote === '"' && $i + 1 < $length) {
                    $word .= $value[++$i];
                } else {
                    $word .= $character;
                }

                continue;
            }
            if ($character === '"' || $character === "'") {
                $quote = $character;
                $hasWord = true;
            } elseif ($character === '\\' && $i + 1 < $length) {
                $word .= $value[++$i];
                $hasWord = true;
            } elseif (ctype_space($character)) {
                if ($hasWord) {
                    $words[] = $word;
                }
                $word = '';
                $hasWord = false;
            } else {
                $word .= $character;
                $hasWord = true;
            }
        }
        if ($hasWord) {
            $words[] = $word;
        }

        return $words;
    }
}
