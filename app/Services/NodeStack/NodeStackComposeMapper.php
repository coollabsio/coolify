<?php

namespace App\Services\NodeStack;

use App\Actions\Node\CreateClusterDockerImageWorkload;
use App\Actions\Node\CreateNodeVolume;
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

    /**
     * Service keys that a workload cannot express yet. The stack runs without them, with a note on
     * the service. `container_name`, `hostname`, `networks`, `platform`, and `logging` are ignored
     * without a note: Nodes name, connect, and log every container themselves.
     */
    private const IGNORED_KEYS = [
        'user' => 'user',
        'shm_size' => 'shm_size',
        'ulimits' => 'ulimits',
        'tmpfs' => 'tmpfs',
        'stop_grace_period' => 'stop_grace_period',
        'deploy' => 'deploy',
        'tty' => 'tty',
        'stdin_open' => 'stdin_open',
    ];

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
     * The workload of each service.
     *
     * @param  array<string, mixed>  $yaml
     * @param  array<string, string>  $variables  See variables().
     * @return list<array{service: string, image: string, command: list<string>, environment: array<string, string>, routes: list<array{scheme: string, host: string, port: int, path: string, noindex: bool}>, health_check: ?array, restart_policy: string, volumes: list<array{name: string, path: string}>, files: list<array{path: string, content_base64: string, mode: string, directory: bool}>, depends_on: array<string, string>, notes: list<string>}>
     */
    public static function services(array $yaml, array $variables): array
    {
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
        foreach ($yaml['services'] as $name => $service) {
            $name = (string) $name;
            $service = is_array($service) ? $service : [];
            // Compose does not start a service with a profile unless the profile is selected.
            if (filled($service['profiles'] ?? null)) {
                continue;
            }
            $notes = array_values(array_map(
                fn (string $key): string => "{$key} is not supported on Nodes yet and is ignored.",
                array_keys(array_intersect_key(self::IGNORED_KEYS, array_filter($service, fn ($value): bool => filled($value)))),
            ));
            $entrypoint = self::words($service['entrypoint'] ?? null, $variables);
            $command = self::words($service['command'] ?? null, $variables);
            if ($entrypoint !== []) {
                // A workload has no entrypoint field: the entrypoint becomes the first words of the command.
                $command = [...$entrypoint, ...$command];
                $notes[] = 'The entrypoint runs as the command, after the entrypoint of the image.';
            }

            $environment = [];
            foreach (self::environment($service) as $key => $value) {
                $environment[$key] = $value === null ? ($variables[$key] ?? '') : self::interpolate((string) $value, $variables);
            }
            ksort($environment);

            [$volumes, $files, $mountNotes] = self::storage($name, $service);
            foreach ($files as $file) {
                $fileBytes += (int) (strlen($file['content_base64']) * 3 / 4);
                $fileCount++;
            }

            $services[] = [
                'service' => $name,
                'image' => self::taggedImage(CreateClusterDockerImageWorkload::qualifiedImage(self::interpolate((string) $service['image'], $variables))),
                'command' => $command,
                'environment' => $environment,
                'routes' => self::routes($service, $variables),
                'health_check' => self::healthCheck($service['healthcheck'] ?? null, $variables),
                'restart_policy' => in_array($name, $oneTime, true) ? 'no' : self::restartPolicy($service['restart'] ?? null),
                'volumes' => $volumes,
                'files' => $files,
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
     * Adds `:latest` to an image without a tag or digest, as Podman reports it in the inventory, so
     * the deployment can match the running container to the revision.
     */
    public static function taggedImage(string $image): string
    {
        if (str_contains($image, '@')) {
            return $image;
        }
        $lastSlash = strrpos($image, '/');
        $lastColon = strrpos($image, ':');

        return $lastColon !== false && ($lastSlash === false || $lastColon > $lastSlash) ? $image : $image.':latest';
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
     * Named volumes stay named volumes, a relative bind (`./data`) is a named volume of the
     * stack, and a `content:` mount is a file that Sentinel writes (decision 0023).
     *
     * @param  array<string, mixed>  $service
     * @return array{0: list<array{name: string, path: string}>, 1: list<array{path: string, content_base64: string, mode: string, directory: bool}>, 2: list<string>}
     */
    private static function storage(string $serviceName, array $service): array
    {
        $volumes = [];
        $files = [];
        $notes = [];
        foreach (NodeStackCompose::mounts($service) as $mount) {
            $path = CreateNodeVolume::normalizeMountPath($mount['target']);
            if ($path === null) {
                throw new RuntimeException("The service {$serviceName} mounts the path {$mount['target']}, which cannot be a mount target.");
            }
            switch ($mount['kind']) {
                case 'named':
                case 'relative':
                    $name = $mount['source'] === null
                        ? $serviceName.'-'.Str::slug(basename($path))
                        : trim(Str::slug(str_replace('/', '-', ltrim((string) $mount['source'], './'))), '-');
                    $volumes[] = ['name' => $name !== '' ? $name : $serviceName.'-data', 'path' => $path];
                    if ($mount['read_only']) {
                        $notes[] = "The volume at {$path} is mounted read-write: read-only volumes are not supported on Nodes yet.";
                    }
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
                default:
                    throw new RuntimeException("The service {$serviceName} mounts a host path, which Nodes do not allow.");
            }
        }

        return [$volumes, $files, $notes];
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
                'CMD' => implode(' ', array_map(fn ($argument): string => escapeshellarg(self::interpolate((string) $argument, $variables)), $test)),
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
