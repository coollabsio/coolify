<?php

namespace App\Services\NodeStack;

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
        'ports' => 'publishes ports (decision 0016)',
        'build' => 'builds an image (build)',
        'volumes_from' => 'mounts the volumes of another service (volumes_from)',
    ];

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
     */
    public static function unavailableReason(array $yaml): ?string
    {
        $owners = [];
        foreach ($yaml['services'] ?? [] as $name => $service) {
            $service = is_array($service) ? $service : [];
            $reason = self::serviceUnavailableReason((string) $name, $service);
            if ($reason !== null) {
                return "The service {$name} {$reason}.";
            }
            foreach (self::mounts($service) as $mount) {
                if (! in_array($mount['kind'], ['named', 'relative'], true) || $mount['source'] === null) {
                    continue;
                }
                $owner = $owners[$mount['source']] ??= (string) $name;
                // A Node volume belongs to one workload (decision 0007).
                if ($owner !== (string) $name) {
                    return "The services {$owner} and {$name} share the volume {$mount['source']}.";
                }
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $service */
    private static function serviceUnavailableReason(string $name, array $service): ?string
    {
        if (! self::validServiceName($name)) {
            return 'has a name that is not a DNS label';
        }
        foreach (self::UNSUPPORTED_KEYS as $key => $reason) {
            $value = $service[$key] ?? null;
            if ($value !== null && $value !== false && $value !== [] && $value !== '') {
                return $reason;
            }
        }
        if (blank($service['image'] ?? null)) {
            return 'has no image';
        }
        foreach (self::mounts($service) as $mount) {
            if ($mount['kind'] === 'socket') {
                return 'mounts the Docker socket';
            }
            if ($mount['kind'] === 'host') {
                return "mounts the host path {$mount['source']}";
            }
        }

        return null;
    }

    /**
     * A service name becomes `<service>.<stack>.coolify.internal`, so it must be a DNS label. Compose
     * names often have `_` (`rallly_db`), which a stack name may have (decision 0023).
     */
    public static function validServiceName(string $name): bool
    {
        return preg_match('/\A(?![-_])[a-z0-9_-]{1,63}(?<![-_])\z/', strtolower($name)) === 1;
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
