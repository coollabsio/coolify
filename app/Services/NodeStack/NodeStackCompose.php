<?php

namespace App\Services\NodeStack;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Reads a Docker Compose file for a stack on Nodes (decision 0023): whether it can run there, and
 * the mounts of each service in one shape. It does not use the v4 service parser, which writes the
 * records of a v4 service; NodeStackComposeMapper reuses its magic variable helpers.
 */
class NodeStackCompose
{
    /** Service keys that a workload cannot express. The first one found is the reason. */
    private const UNSUPPORTED_KEYS = [
        'privileged' => 'runs privileged',
        'cap_add' => 'adds Linux capabilities (cap_add)',
        'devices' => 'uses host devices',
        'security_opt' => 'changes security options (security_opt)',
        'network_mode' => 'sets network_mode',
        'pid' => 'shares a PID namespace (pid)',
        'sysctls' => 'sets kernel parameters (sysctls)',
        'ports' => 'publishes ports, which needs Sentinel with network.firewall.published_ports.v1 on every server of the cluster',
        'build' => 'builds an image (build)',
    ];

    /** Keys of `build:` that the Node builder cannot use. The first one found is the reason. */
    private const UNSUPPORTED_BUILD_KEYS = [
        'dockerfile_inline' => 'has an inline Dockerfile (build.dockerfile_inline)',
        'additional_contexts' => 'uses additional build contexts (build.additional_contexts)',
        'ssh' => 'forwards SSH to the build (build.ssh)',
        'secrets' => 'uses build secrets (build.secrets)',
    ];

    /** Keys of `build:` that the Node builder uses. Other keys are ignored with a note. */
    private const SUPPORTED_BUILD_KEYS = ['context', 'dockerfile', 'args', 'target'];

    /**
     * @return array<string, mixed> The parsed Compose file.
     *
     * @throws RuntimeException When the file is not valid YAML or has no services.
     */
    public static function parse(string $compose): array
    {
        try {
            $yaml = parseDockerComposeYaml($compose);
        } catch (Throwable $exception) {
            throw new RuntimeException('The Compose file is not valid YAML: '.$exception->getMessage());
        }
        if (! is_array($yaml) || ! is_array($yaml['services'] ?? null) || $yaml['services'] === []) {
            throw new RuntimeException('The Compose file has no services.');
        }

        return $yaml;
    }

    /**
     * Why the Compose file cannot run on Nodes, or null when it can.
     *
     * @param  array<string, mixed>  $yaml
     * @param  ?string  $gitComposeDirectory  The directory of the Compose file in its Git repository. Only a file from Git can `build:`.
     * @param  bool  $publishedPorts  Every server of the cluster publishes ports (decision 0016), so `ports:` can run.
     * @param  bool  $containerSocket  Every server of the cluster mounts the Podman socket in a container, so a mount of the Docker socket can run.
     */
    public static function unavailableReason(array $yaml, bool $underscoreNames = false, ?string $gitComposeDirectory = null, bool $publishedPorts = false, bool $containerSocket = false): ?string
    {
        if ($gitComposeDirectory !== null && filled($yaml['include'] ?? null)) {
            return 'The Compose file includes other Compose files (include).';
        }
        $services = $yaml['services'] ?? [];
        foreach ($services as $name => $service) {
            $service = is_array($service) ? $service : [];
            $reason = self::serviceUnavailableReason((string) $name, $service, $underscoreNames, $gitComposeDirectory, $publishedPorts, $containerSocket);
            if ($reason !== null) {
                return "The service {$name} {$reason}.";
            }
            // Services of one stack share a volume on one Node (decision 0024), also through volumes_from.
            foreach (self::volumesFrom($service) as $entry) {
                if ($entry['container']) {
                    return "The service {$name} mounts the volumes of the container {$entry['service']} (volumes_from), which is not a service of this stack.";
                }
                if (! is_array($services[$entry['service']] ?? null)) {
                    return "The service {$name} mounts the volumes of {$entry['service']} (volumes_from), which is not a service of this stack.";
                }
            }
        }

        return null;
    }

    /**
     * Whether a service of the Compose file mounts the Docker or Podman socket. Such a service
     * controls every container on its server, so only a team admin may create, change, or deploy it.
     *
     * @param  array<string, mixed>  $yaml
     */
    public static function mountsContainerSocket(array $yaml): bool
    {
        foreach ($yaml['services'] ?? [] as $service) {
            foreach (self::mounts(is_array($service) ? $service : []) as $mount) {
                if ($mount['kind'] === 'socket') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Refuses a user who is not an admin or owner of the team: the Docker socket reaches every
     * container on the server, also resources outside the access grants of a member. A change
     * without a user (the reconciler, a webhook) is allowed, because the team admin trusted the
     * repository.
     *
     * @param  string  $subject  What mounts the socket, for the message, for example `The service web`.
     *
     * @throws RuntimeException
     */
    public static function ensureMayMountContainerSocket(?User $user, int $teamId, string $subject = 'This service'): void
    {
        if ($user !== null && ! $user->isAdminOfTeam($teamId)) {
            throw new RuntimeException("{$subject} mounts the Docker socket, which controls every container on its server. Only a team admin or owner can create, change, or deploy it.");
        }
    }

    /**
     * The `volumes_from` entries of a service: `service`, `service:ro`, `service:rw`, or
     * `container:<name>`, which Nodes do not have.
     *
     * @param  array<string, mixed>  $service
     * @return list<array{service: string, read_only: bool, container: bool}>
     */
    public static function volumesFrom(array $service): array
    {
        $entries = [];
        foreach ((array) ($service['volumes_from'] ?? []) as $entry) {
            $entry = trim((string) $entry);
            if ($entry === '') {
                continue;
            }
            $container = str_starts_with($entry, 'container:');
            if ($container) {
                $entry = substr($entry, strlen('container:'));
            }
            $mode = str_contains($entry, ':') ? strtolower(substr($entry, strrpos($entry, ':') + 1)) : '';
            $entries[] = [
                'service' => in_array($mode, ['ro', 'rw'], true) ? substr($entry, 0, strrpos($entry, ':')) : $entry,
                'read_only' => $mode === 'ro',
                'container' => $container,
            ];
        }

        return $entries;
    }

    /** The Compose file of a one-click service template, or null when it has none. */
    public static function templateCompose(string $template, ?Collection $templates = null): ?string
    {
        $encoded = data_get(($templates ?? get_service_templates())->get($template), 'compose');
        $compose = is_string($encoded) ? base64_decode($encoded, true) : false;

        return $compose === false || $compose === '' ? null : $compose;
    }

    /**
     * Why each one-click service template cannot run on Nodes, keyed by template. A template that
     * can run is absent. Cached for the current template set.
     *
     * @return array<string, string>
     */
    public static function templateUnavailableReasons(?Collection $templates = null, bool $underscoreNames = false, bool $publishedPorts = false, bool $containerSocket = false): array
    {
        $templates ??= get_service_templates();
        $composes = $templates->map(fn ($template): string => (string) data_get($template, 'compose', ''));
        $features = ($underscoreNames ? 'underscore:' : '').($publishedPorts ? 'ports:' : '').($containerSocket ? 'socket:' : '');

        return Cache::remember('node-stack-template-availability:'.$features.md5($composes->toJson()), now()->addDay(), function () use ($composes, $underscoreNames, $publishedPorts, $containerSocket): array {
            $reasons = [];
            foreach ($composes as $key => $encoded) {
                try {
                    $reason = self::unavailableReason(self::parse((string) base64_decode($encoded)), $underscoreNames, publishedPorts: $publishedPorts, containerSocket: $containerSocket);
                } catch (RuntimeException $exception) {
                    $reason = $exception->getMessage();
                }
                if ($reason !== null) {
                    $reasons[(string) $key] = $reason;
                }
            }

            return $reasons;
        });
    }

    /**
     * The `build:` of a service from Git, resolved in the repository. The context is relative to
     * the directory of the Compose file, and the Dockerfile to the context, as in Compose. The
     * Node builder clones the repository and builds `context` with `dockerfile`, which must be
     * inside the context.
     *
     * @param  string  $composeDirectory  The directory of the Compose file in the repository, `` for the root.
     * @return array{context: string, dockerfile: string, args: mixed, target: ?string, notes: list<string>} `context` is relative to the root of the repository, `` for the root.
     *
     * @throws RuntimeException With the reason, which follows "The service <name>", when the builder cannot build it.
     */
    public static function build(mixed $build, string $composeDirectory): array
    {
        $build = is_string($build) ? ['context' => $build] : $build;
        if (! is_array($build)) {
            throw new RuntimeException('has a build that is not a path or a map');
        }
        foreach (self::UNSUPPORTED_BUILD_KEYS as $key => $reason) {
            if (filled($build[$key] ?? null)) {
                throw new RuntimeException($reason);
            }
        }
        $context = trim((string) ($build['context'] ?? '.'));
        $context = $context === '' ? '.' : $context;
        if (preg_match('/\A[a-z][a-z0-9+.-]*:\/\//i', $context) === 1 || preg_match('/\A[^\/]+@[^\/:]+:/', $context) === 1 || str_starts_with($context, 'git@')) {
            throw new RuntimeException("builds from a remote context ({$context})");
        }
        if (str_starts_with($context, '/') || str_starts_with($context, '~') || str_contains($context, '$')) {
            throw new RuntimeException("builds from a context outside the repository ({$context})");
        }
        $contextPath = self::normalizedPath($composeDirectory.'/'.$context)
            ?? throw new RuntimeException("builds from a context outside the repository ({$context})");
        $dockerfile = trim((string) ($build['dockerfile'] ?? 'Dockerfile'));
        $dockerfile = $dockerfile === '' ? 'Dockerfile' : $dockerfile;
        $dockerfilePath = str_starts_with($dockerfile, '/') ? null : self::normalizedPath($contextPath.'/'.$dockerfile);
        $insideContext = filled($dockerfilePath) && ($contextPath === '' || str_starts_with($dockerfilePath, $contextPath.'/'));
        if (! $insideContext) {
            throw new RuntimeException("has a Dockerfile outside its build context ({$dockerfile}). The Node builder needs it inside the context");
        }
        $ignored = array_diff(array_keys($build), self::SUPPORTED_BUILD_KEYS);

        return [
            'context' => $contextPath,
            'dockerfile' => $contextPath === '' ? $dockerfilePath : substr($dockerfilePath, strlen($contextPath) + 1),
            'args' => $build['args'] ?? [],
            'target' => filled($build['target'] ?? null) ? (string) $build['target'] : null,
            'notes' => $ignored === [] ? [] : ['build.'.implode(', build.', $ignored).' '.(count($ignored) === 1 ? 'is' : 'are').' not supported on Nodes and ignored.'],
        ];
    }

    /** `a/./b/../c` becomes `a/c`; null when the path goes above the root. */
    private static function normalizedPath(string $path): ?string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    /** @param  array<string, mixed>  $service */
    private static function serviceUnavailableReason(string $name, array $service, bool $underscoreNames, ?string $gitComposeDirectory, bool $publishedPorts, bool $containerSocket): ?string
    {
        if (! self::validServiceName($name, $underscoreNames)) {
            return $underscoreNames || ! self::validServiceName($name, true)
                ? 'has a name that is not a DNS label (letters, digits, and -)'
                : 'has a name with _, which needs Sentinel with discovery.names.underscore.v1 on every server of the cluster';
        }
        $builds = $gitComposeDirectory !== null && filled($service['build'] ?? null);
        foreach (self::UNSUPPORTED_KEYS as $key => $reason) {
            $value = $service[$key] ?? null;
            if (($key === 'build' && $builds) || ($key === 'ports' && $publishedPorts)) {
                continue;
            }
            if ($value !== null && $value !== false && $value !== [] && $value !== '') {
                return $reason;
            }
        }
        if ($gitComposeDirectory !== null && is_array($service['extends'] ?? null) && filled($service['extends']['file'] ?? null)) {
            return 'extends a service of another Compose file (extends.file)';
        }
        if ($builds) {
            try {
                self::build($service['build'], $gitComposeDirectory);
            } catch (RuntimeException $exception) {
                return $exception->getMessage();
            }
        } elseif (blank($service['image'] ?? null)) {
            return 'has no image';
        }
        foreach (self::mounts($service) as $mount) {
            if ($mount['kind'] === 'socket' && ! $containerSocket) {
                return 'mounts the Docker socket, which needs Sentinel with workload.container_socket.v1 on every server of the cluster';
            }
            if ($mount['kind'] === 'host') {
                return "mounts the host path {$mount['source']}";
            }
        }

        return null;
    }

    /**
     * A service name becomes `<service>.<stack>.coolify.internal`, so it must be a DNS label.
     * Compose names often have `_` (`rallly_db`): Sentinel accepts `_` inside a stack name with
     * `discovery.names.underscore.v1`, and rejects the whole name table without it.
     */
    public static function validServiceName(string $name, bool $underscoreNames = false): bool
    {
        $pattern = $underscoreNames ? '/\A(?![-_])[a-z0-9_-]{1,63}(?<![-_])\z/' : '/\A(?!-)[a-z0-9-]{1,63}(?<!-)\z/';

        return preg_match($pattern, strtolower($name)) === 1;
    }

    /**
     * The mounts of one service:
     * - `named`: a Compose volume, also an anonymous one (`source` is null);
     * - `relative`: a bind of a path in the stack directory (`./data`), which becomes a named volume of the stack;
     * - `content`: a Coolify file mount with its content;
     * - `host`: an absolute host path; `socket`: the Docker or Podman socket;
     * - `tmpfs`: a tmpfs mount.
     *
     * @param  array<string, mixed>  $service
     * @return list<array{kind: string, source: ?string, target: string, read_only: bool, content?: string}>
     */
    public static function mounts(array $service): array
    {
        $mounts = [];
        foreach ((array) ($service['volumes'] ?? []) as $volume) {
            if (is_string($volume)) {
                $mounts[] = self::shortMount($volume);
            } elseif (is_array($volume)) {
                $mounts[] = self::longMount($volume);
            }
        }

        return array_values(array_filter($mounts));
    }

    /** @return array{kind: string, source: ?string, target: string, read_only: bool}|null */
    private static function shortMount(string $volume): ?array
    {
        $parsed = parseDockerVolumeString($volume);
        $source = $parsed['source']?->value();
        $target = (string) $parsed['target']?->value();
        if ($target === '') {
            return null;
        }
        $readOnly = str_contains((string) $parsed['mode']?->value(), 'ro');
        // `- /data` is an anonymous volume: parseDockerVolumeString returns the path as source and target.
        if ($source === null || $source === $target && ! str_contains($volume, ':')) {
            return ['kind' => 'named', 'source' => null, 'target' => $target, 'read_only' => $readOnly];
        }

        return ['kind' => self::sourceKind($source), 'source' => resolveComposeVolumeSourceDefault($source), 'target' => $target, 'read_only' => $readOnly];
    }

    /**
     * @param  array<string, mixed>  $volume
     * @return array{kind: string, source: ?string, target: string, read_only: bool, content?: string}|null
     */
    private static function longMount(array $volume): ?array
    {
        $target = (string) ($volume['target'] ?? '');
        if ($target === '') {
            return null;
        }
        $type = (string) ($volume['type'] ?? 'volume');
        $source = isset($volume['source']) ? (string) $volume['source'] : null;
        $readOnly = (bool) ($volume['read_only'] ?? false);
        if ($type === 'tmpfs') {
            return ['kind' => 'tmpfs', 'source' => null, 'target' => $target, 'read_only' => false];
        }
        if ($type === 'volume') {
            return ['kind' => 'named', 'source' => blank($source) ? null : $source, 'target' => $target, 'read_only' => $readOnly];
        }
        if (array_key_exists('content', $volume) && $volume['content'] !== null && ! ($volume['isDirectory'] ?? $volume['is_directory'] ?? false)) {
            return ['kind' => 'content', 'source' => $source, 'target' => $target, 'read_only' => $readOnly, 'content' => (string) $volume['content']];
        }
        if (blank($source)) {
            return null;
        }

        return ['kind' => self::sourceKind($source), 'source' => resolveComposeVolumeSourceDefault($source), 'target' => $target, 'read_only' => $readOnly];
    }

    private static function sourceKind(string $source): string
    {
        $source = resolveComposeVolumeSourceDefault($source);
        if (Str::contains($source, ['docker.sock', 'podman.sock'])) {
            return 'socket';
        }

        return match (true) {
            str_starts_with($source, '.') => 'relative',
            str_starts_with($source, '/'), str_starts_with($source, '~'), str_starts_with($source, '$') => 'host',
            default => 'named',
        };
    }
}
