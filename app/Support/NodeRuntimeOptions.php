<?php

namespace App\Support;

use App\Services\NodeStack\NodeStackComposeMapper;
use RuntimeException;

/**
 * The container runtime options of a cluster application (decision 0032): the v4 Custom Docker
 * Options text, the container labels, and the GPU settings, as the typed fields of Sentinel
 * (`workload.runtime_options.v1` and `.v2`, `workload.gpu.v1`). Sentinel never gets the text.
 *
 * Each method throws a RuntimeException that names the flag or the line that breaks a rule.
 */
final class NodeRuntimeOptions
{
    public const MAX_LABELS = 64;

    public const MAX_EXTRA_HOSTS = 32;

    public const MAX_ENTRYPOINT_WORDS = 64;

    public const MAX_PIDS_LIMIT = 4194304;

    public const MAX_SYSCTLS = 16;

    public const MAX_GPU_DEVICES = 16;

    /** The keys of `runtime_options` that need `workload.runtime_options.v2`. */
    public const V2_KEYS = ['init', 'read_only', 'pids_limit', 'hostname', 'entrypoint', 'extra_hosts', 'cap_add', 'cap_drop', 'no_new_privileges', 'sysctls'];

    /** Label keys that Sentinel and Coolify keep for themselves. */
    public const RESERVED_LABEL_PREFIXES = ['coolify.', 'io.containers.', 'io.podman.'];

    /** The page that sets a v4 flag that Nodes refuse in the text. */
    private const OTHER_PAGES = [
        '-e' => 'Environment Variables', '--env' => 'Environment Variables', '--env-file' => 'Environment Variables',
        '-v' => 'Persistent Storage', '--volume' => 'Persistent Storage', '--mount' => 'Persistent Storage',
        '-p' => 'Published Ports', '--publish' => 'Published Ports', '-P' => 'Published Ports', '--expose' => 'Published Ports',
        '-m' => 'Resource Limits', '--memory' => 'Resource Limits', '--memory-reservation' => 'Resource Limits',
        '--cpus' => 'Resource Limits', '--cpu-shares' => 'Resource Limits',
        '-u' => 'the user of the image', '--user' => 'the user of the image',
        '--health-cmd' => 'Healthcheck', '--restart' => 'the restart policy of Coolify',
        '-l' => 'Container Labels', '--label' => 'Container Labels',
    ];

    /** Flags that v4 maps, but that Nodes refuse, with the reason. */
    private const REFUSED = [
        'privileged' => ['--privileged', 'it gives the container root on the server'],
        'devices' => ['--device', 'it gives the container a device of the server. Use the GPU settings for a GPU'],
        'ip' => ['--ip', 'Coolify gives each container its address, and the cluster firewall uses it'],
        'ip6' => ['--ip6', 'Coolify gives each container its address, and the cluster firewall uses it'],
        'dns' => ['--dns', 'the container must use the DNS of the cluster for service discovery'],
        'runtime' => ['--runtime', 'a server has one container runtime. A GPU uses CDI, not the NVIDIA runtime'],
    ];

    /**
     * The typed options of the Custom Docker Options text.
     *
     * @return array{runtime_options: array<string, mixed>, gpu: array{count?: int|string, device_ids?: list<string>}|null}
     */
    public static function parseDockerRunOptions(?string $text): array
    {
        $text = trim((string) $text);
        if ($text === '') {
            return ['runtime_options' => [], 'gpu' => null];
        }
        if (strlen($text) > 16384) {
            throw new RuntimeException('Custom Docker Options can have at most 16384 characters.');
        }
        foreach (unsupportedDockerRunOptions($text) as $flag) {
            $page = self::OTHER_PAGES[$flag] ?? null;
            throw new RuntimeException($page === null
                ? "Nodes do not support the Docker option {$flag}."
                : "Nodes do not support the Docker option {$flag}. Use {$page} instead.");
        }
        $compose = convertDockerRunToCompose($text);
        foreach (self::REFUSED as $key => [$flag, $reason]) {
            if (filled($compose[$key] ?? null) && ($compose[$key] ?? null) !== false) {
                throw new RuntimeException("Nodes do not support the Docker option {$flag}, because {$reason}.");
            }
        }

        $options = NodeStackComposeMapper::runtimeOptions('', collect($compose)->only(['shm_size', 'ulimits', 'tmpfs', 'tty', 'stdin_open'])->all(), [], null, [], 'Custom Docker Options');
        if (($compose['init'] ?? false) === true) {
            $options['init'] = true;
        }
        if (($compose['read_only'] ?? false) === true) {
            $options['read_only'] = true;
        }
        if (array_key_exists('pids_limit', $compose)) {
            $limit = (int) $compose['pids_limit'];
            if ($limit !== -1 && ($limit < 1 || $limit > self::MAX_PIDS_LIMIT)) {
                throw new RuntimeException('--pids-limit must be -1 or 1 to '.self::MAX_PIDS_LIMIT.'.');
            }
            $options['pids_limit'] = $limit;
        }
        if (filled($compose['hostname'] ?? null)) {
            $hostname = (string) $compose['hostname'];
            if (preg_match('/\A(?!-)[A-Za-z0-9-]{1,63}(?<!-)\z/', $hostname) !== 1) {
                throw new RuntimeException("--hostname {$hostname} is not one DNS label of at most 63 letters, digits, and -.");
            }
            $options['hostname'] = $hostname;
        }
        if (filled($compose['entrypoint'] ?? null)) {
            $words = array_values(array_filter(str_getcsv(trim((string) $compose['entrypoint']), ' ', '"', ''), fn (?string $word): bool => $word !== null && $word !== ''));
            if ($words === [] || count($words) > self::MAX_ENTRYPOINT_WORDS || collect($words)->contains(fn (string $word): bool => strlen($word) > 4096 || str_contains($word, "\0"))) {
                throw new RuntimeException('--entrypoint must have 1 to '.self::MAX_ENTRYPOINT_WORDS.' words with at most 4096 characters each.');
            }
            $options['entrypoint'] = $words;
        }
        $hosts = self::listValues($compose['extra_hosts'] ?? []);
        if (count($hosts) > self::MAX_EXTRA_HOSTS) {
            throw new RuntimeException('Use --add-host at most '.self::MAX_EXTRA_HOSTS.' times.');
        }
        foreach ($hosts as $host) {
            $options['extra_hosts'][] = self::extraHost($host);
        }
        foreach (self::listValues($compose['cap_drop'] ?? []) as $capability) {
            $name = ContainerIsolation::normalizeCapability($capability);
            if ($name !== 'ALL' && ! in_array($name, ContainerIsolation::LINUX_CAPABILITIES, true)) {
                throw new RuntimeException("--cap-drop {$capability} is not a Linux capability.");
            }
            $options['cap_drop'][] = $name;
        }
        foreach (self::listValues($compose['cap_add'] ?? []) as $capability) {
            $name = ContainerIsolation::normalizeCapability($capability);
            if (ContainerIsolation::capabilityTier($name) === 3) {
                throw new RuntimeException("Nodes do not support --cap-add {$capability}. It gives the container too much control of its server. Allowed: "
                    .implode(', ', [...ContainerIsolation::TIER1_CAP_ADD, ...ContainerIsolation::TIER2_CAP_ADD]).' (the last six need a team admin).');
            }
            if (in_array($name, $options['cap_drop'] ?? [], true)) {
                throw new RuntimeException("{$name} is in --cap-add and in --cap-drop.");
            }
            $options['cap_add'][] = $name;
        }
        foreach (self::listValues($compose['security_opt'] ?? []) as $value) {
            if (! in_array($value, ['no-new-privileges', 'no-new-privileges:true', 'no-new-privileges=true'], true)) {
                throw new RuntimeException("Nodes do not support --security-opt {$value}. Only no-new-privileges is allowed, because other values lower the isolation of the container.");
            }
            $options['no_new_privileges'] = true;
        }
        $sysctls = self::listValues($compose['sysctls'] ?? []);
        if (count($sysctls) > self::MAX_SYSCTLS) {
            throw new RuntimeException('Use --sysctl at most '.self::MAX_SYSCTLS.' times.');
        }
        foreach ($sysctls as $sysctl) {
            [$name, $value] = array_pad(explode('=', $sysctl, 2), 2, null);
            $name = trim((string) $name);
            $value = trim((string) $value);
            if (ContainerIsolation::sysctlTier($name) === 3) {
                throw new RuntimeException("Nodes do not support --sysctl {$name}. Allowed: ".implode(', ', [...ContainerIsolation::TIER1_SYSCTLS, ...ContainerIsolation::TIER2_SYSCTLS]).', and the forwarding and src_valid_mark sysctls of an interface (a team admin only for the last ones).');
            }
            if (preg_match(ContainerIsolation::SYSCTL_VALUE_PATTERN, $value) !== 1) {
                throw new RuntimeException("--sysctl {$name} needs a value of one or two numbers, like {$name}=1.");
            }
            if (collect($options['sysctls'] ?? [])->contains('name', $name)) {
                throw new RuntimeException("--sysctl {$name} is set more than once.");
            }
            $options['sysctls'][] = ['name' => $name, 'value' => $value];
        }
        foreach (['extra_hosts', 'cap_add', 'cap_drop'] as $key) {
            if (isset($options[$key])) {
                $options[$key] = array_values(array_unique($options[$key], SORT_REGULAR));
            }
        }

        $gpu = null;
        $device = data_get($compose, 'deploy.resources.reservations.devices.0');
        if (is_array($device)) {
            $gpu = isset($device['device_ids']) ? ['device_ids' => array_values($device['device_ids'])] : ['count' => $device['count'] ?? 'all'];
        }

        return ['runtime_options' => $options, 'gpu' => $gpu];
    }

    /**
     * The container labels: one `key=value` on each line. Empty lines and lines that start with `#`
     * are skipped.
     *
     * @return array{labels: list<array{key: string, value: string}>, proxy_labels: bool}
     */
    public static function parseLabels(?string $text): array
    {
        $labels = [];
        $proxyLabels = false;
        foreach (preg_split('/\r\n|\r|\n/', (string) $text) ?: [] as $number => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (! str_contains($line, '=')) {
                throw new RuntimeException('Line '.($number + 1).' of the labels has no =. Write each label as key=value.');
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $lower = strtolower($key);
            if (preg_match('#\A[A-Za-z0-9][A-Za-z0-9._/-]{0,254}\z#', $key) !== 1) {
                throw new RuntimeException("The label key {$key} is not valid. Use 1 to 255 letters, digits, ., _, /, or -, starting with a letter or a digit.");
            }
            foreach (self::RESERVED_LABEL_PREFIXES as $prefix) {
                if (str_starts_with($lower, $prefix)) {
                    throw new RuntimeException("The label {$key} cannot be set. Labels that start with {$prefix} belong to Coolify or Podman.");
                }
            }
            if ($lower === 'podman_systemd_unit') {
                throw new RuntimeException("The label {$key} cannot be set. It belongs to Podman.");
            }
            if (strlen($value) > 4096 || str_contains($value, "\0")) {
                throw new RuntimeException("The value of the label {$key} can have at most 4096 characters.");
            }
            if (collect($labels)->contains('key', $key)) {
                throw new RuntimeException("The label {$key} is set more than once.");
            }
            $proxyLabels = $proxyLabels || str_starts_with($lower, 'traefik.') || str_starts_with($lower, 'caddy');
            $labels[] = ['key' => $key, 'value' => $value];
        }
        if (count($labels) > self::MAX_LABELS) {
            throw new RuntimeException('Use at most '.self::MAX_LABELS.' labels.');
        }

        return ['labels' => $labels, 'proxy_labels' => $proxyLabels];
    }

    /**
     * The GPU request of the GPU settings: a count (a number or `all`) or device IDs (indexes or
     * `GPU-<uuid>`). Device IDs replace the count.
     *
     * @return array{count?: int|string, device_ids?: list<string>}
     */
    public static function gpuRequest(?string $count, ?string $deviceIds): array
    {
        $ids = array_values(array_filter(array_map('trim', explode(',', (string) $deviceIds)), fn (string $id): bool => $id !== ''));
        if ($ids !== []) {
            if (count($ids) > self::MAX_GPU_DEVICES || collect($ids)->contains(fn (string $id): bool => preg_match('/\A([0-9]{1,3}|GPU-[0-9a-fA-F-]{8,64})\z/', $id) !== 1)) {
                throw new RuntimeException('Device IDs are GPU indexes, like 0,1, or UUIDs, like GPU-4a6b..., at most '.self::MAX_GPU_DEVICES.'.');
            }

            return ['device_ids' => array_values(array_unique($ids))];
        }
        $count = strtolower(trim((string) $count));
        if ($count === '' || $count === 'all') {
            return ['count' => 'all'];
        }
        if (! ctype_digit($count) || (int) $count < 1 || (int) $count > self::MAX_GPU_DEVICES) {
            throw new RuntimeException('The GPU count is a number from 1 to '.self::MAX_GPU_DEVICES.', or all.');
        }

        return ['count' => (int) $count];
    }

    /**
     * The CDI names that a GPU request uses on a Node, from the devices that its Sentinel reports.
     *
     * @param  array{count?: int|string, device_ids?: list<string>}  $gpu
     * @param  list<string>  $inventory  `nvidia.com/gpu=<name>` names.
     * @return list<string>
     *
     * @throws RuntimeException When the Node does not have the devices.
     */
    public static function cdiDevices(array $gpu, array $inventory, string $nodeName): array
    {
        $names = collect($inventory)->map(fn (string $name): string => substr($name, strlen('nvidia.com/gpu=')))
            ->filter(fn (string $name): bool => $name !== '');
        if ($names->isEmpty()) {
            throw new RuntimeException("The server {$nodeName} reports no NVIDIA GPU in its CDI specs.");
        }
        if (isset($gpu['device_ids'])) {
            $missing = array_values(array_diff($gpu['device_ids'], $names->all()));
            if ($missing !== []) {
                throw new RuntimeException("The server {$nodeName} has no GPU ".implode(', ', $missing).'.');
            }

            return array_map(fn (string $id): string => "nvidia.com/gpu={$id}", $gpu['device_ids']);
        }
        if (($gpu['count'] ?? 'all') === 'all') {
            if (! $names->contains('all')) {
                throw new RuntimeException("The CDI spec of the server {$nodeName} has no device all.");
            }

            return ['nvidia.com/gpu=all'];
        }
        $indexes = $names->filter(fn (string $name): bool => ctype_digit($name))->sort(SORT_NUMERIC)->values();
        if ($indexes->count() < (int) $gpu['count']) {
            throw new RuntimeException("The server {$nodeName} has {$indexes->count()} GPUs, and the application asks for {$gpu['count']}.");
        }

        return $indexes->take((int) $gpu['count'])->map(fn (string $index): string => "nvidia.com/gpu={$index}")->values()->all();
    }

    /** @return array{name: string, ip: string} */
    private static function extraHost(string $value): array
    {
        $separator = strpos($value, '=') !== false && (strpos($value, ':') === false || strpos($value, '=') < strpos($value, ':')) ? '=' : ':';
        [$name, $ip] = array_pad(explode($separator, $value, 2), 2, '');
        $name = trim($name);
        $ip = trim(trim($ip), '[]');
        if ($ip === 'host-gateway') {
            throw new RuntimeException("--add-host {$value} is not supported. host-gateway would reach the server itself, and the cluster firewall controls that access.");
        }
        if (strlen($name) > 253 || preg_match('/\A(?!-)[A-Za-z0-9-]{1,63}(?<!-)(\.(?!-)[A-Za-z0-9-]{1,63}(?<!-))*\z/', $name) !== 1 || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException("--add-host {$value} needs a host name and an IP address, like db.internal:10.0.0.5.");
        }

        return ['name' => $name, 'ip' => $ip];
    }

    /**
     * The values of a list option of convertDockerRunToCompose(), split at commas.
     *
     * @return list<string>
     */
    private static function listValues(mixed $value): array
    {
        $values = [];
        foreach ((array) $value as $item) {
            foreach (explode(',', (string) $item) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $values[] = $part;
                }
            }
        }

        return array_values(array_unique($values));
    }
}
