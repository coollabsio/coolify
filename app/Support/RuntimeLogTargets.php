<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Groups running and stopped containers for the runtime logs page.
 *
 * A service is one stream. Every container of that service, on every server and
 * from a rollout that left the previous container behind, is a source of that stream.
 * Deployments are production, then preview pull requests from newest to oldest.
 * Services are alphabetical.
 */
class RuntimeLogTargets
{
    /**
     * @param  list<array{uuid: string, name: string, containers: list<array<string, mixed>>}>  $servers
     * @return array{
     *     deployments: list<array{value: string, label: string, labelClass: string}>,
     *     services: list<array{value: string, label: string, dot: string, description?: string, state?: string}>,
     *     deployment: string,
     *     service: string,
     *     sources: list<array{server: string, container: string, label: string}>,
     *     streamKey: string,
     * }
     */
    public static function select(array $servers, string $deployment, string $service, bool $groupDeployments): array
    {
        $containers = self::containers($servers, $groupDeployments);
        if ($containers === []) {
            return self::emptySelection();
        }

        $deploymentIds = $groupDeployments ? self::sortedDeploymentIds($containers) : [];
        $chosenDeployment = $groupDeployments
            ? self::pickDeployment($deploymentIds, self::normalizeDeployment($deployment))
            : '0';

        $visible = array_values(array_filter(
            $containers,
            fn (array $container): bool => $container['deployment'] === $chosenDeployment,
        ));
        if ($visible === []) {
            return self::emptySelection();
        }

        $groups = self::groupByService($visible);
        $chosenKey = self::pickServiceKey($groups, $service);
        $sources = self::sourcesFor($groups[$chosenKey]);

        return [
            'deployments' => $groupDeployments ? self::deploymentOptions($deploymentIds) : [],
            'services' => self::serviceOptions($groups),
            'deployment' => $chosenDeployment,
            'service' => count($groups) > 1 ? $chosenKey : '',
            'sources' => $sources,
            'streamKey' => self::streamKey($sources),
        ];
    }

    /**
     * @return array{
     *     deployments: list<empty>,
     *     services: list<empty>,
     *     deployment: string,
     *     service: string,
     *     sources: list<empty>,
     *     streamKey: string,
     * }
     */
    private static function emptySelection(): array
    {
        return [
            'deployments' => [],
            'services' => [],
            'deployment' => '0',
            'service' => '',
            'sources' => [],
            'streamKey' => '',
        ];
    }

    /**
     * @param  list<array{uuid: string, name: string, containers: list<array<string, mixed>>}>  $servers
     * @return list<array{name: string, key: string, state: string, deployment: string, server: string, serverName: string, serverIndex: int}>
     */
    private static function containers(array $servers, bool $groupDeployments): array
    {
        $containers = [];
        foreach (array_values($servers) as $index => $server) {
            foreach ($server['containers'] ?? [] as $container) {
                if (! is_array($container)) {
                    continue;
                }
                $name = (string) data_get($container, 'Names', '');
                if (! ValidationPatterns::isValidContainerName($name)) {
                    continue;
                }
                $labels = self::labels($container);
                $state = strtolower(trim((string) data_get($container, 'State', 'running')));
                $containers[] = [
                    'name' => $name,
                    'key' => self::serviceKey($name, $labels),
                    'state' => $state !== '' ? $state : 'running',
                    'deployment' => self::deploymentId($labels, $groupDeployments),
                    'server' => (string) ($server['uuid'] ?? ''),
                    'serverName' => (string) ($server['name'] ?? ''),
                    'serverIndex' => $index,
                ];
            }
        }

        return $containers;
    }

    /**
     * @param  array<string, mixed>  $container
     */
    private static function labels(array $container): Collection
    {
        $raw = $container['Labels'] ?? null;
        if (is_array($raw)) {
            return collect($raw);
        }
        if (! is_string($raw) || trim($raw) === '') {
            return collect();
        }

        $parsed = format_docker_labels_to_json($raw);

        return $parsed instanceof Collection ? $parsed : collect();
    }

    private static function serviceKey(string $containerName, Collection $labels): string
    {
        $compose = trim((string) $labels->get('com.docker.compose.service', ''));
        if ($compose !== '') {
            return $compose;
        }

        $name = trim((string) $labels->get('coolify.name', ''));
        if ($name !== '') {
            return $name;
        }

        $headline = str($containerName)->beforeLast('-')->headline()->toString();

        return $headline !== '' ? $headline : $containerName;
    }

    private static function deploymentId(Collection $labels, bool $groupDeployments): string
    {
        if (! $groupDeployments) {
            return '0';
        }

        return self::normalizeDeployment((string) $labels->get('coolify.pullRequestId', ''));
    }

    private static function normalizeDeployment(string $deployment): string
    {
        if ($deployment === '' || ! ctype_digit($deployment) || (int) $deployment === 0) {
            return '0';
        }

        return (string) (int) $deployment;
    }

    /**
     * @param  list<array{deployment: string}>  $containers
     * @return list<string>
     */
    private static function sortedDeploymentIds(array $containers): array
    {
        $ids = array_values(array_unique(array_column($containers, 'deployment')));
        usort($ids, function (string $left, string $right): int {
            if ($left === '0') {
                return -1;
            }
            if ($right === '0') {
                return 1;
            }

            return (int) $right <=> (int) $left;
        });

        return $ids;
    }

    /**
     * @param  list<string>  $deploymentIds
     */
    private static function pickDeployment(array $deploymentIds, string $preferred): string
    {
        if (in_array($preferred, $deploymentIds, true)) {
            return $preferred;
        }
        if (in_array('0', $deploymentIds, true)) {
            return '0';
        }

        return $deploymentIds[0] ?? '0';
    }

    /**
     * @param  list<string>  $ids
     * @return list<array{value: string, label: string, labelClass: string}>
     */
    private static function deploymentOptions(array $ids): array
    {
        return array_map(fn (string $id): array => [
            'value' => $id,
            'label' => $id === '0' ? 'Production' : 'PR #'.$id,
            'labelClass' => $id === '0'
                ? 'deployment-badge deployment-badge-production'
                : 'deployment-badge deployment-badge-preview',
        ], $ids);
    }

    /**
     * @param  list<array{key: string}>  $containers
     * @return array<string, list<array{name: string, key: string, state: string, deployment: string, server: string, serverName: string, serverIndex: int}>>
     */
    private static function groupByService(array $containers): array
    {
        $groups = [];
        foreach ($containers as $container) {
            $groups[$container['key']][] = $container;
        }
        uksort($groups, fn (string $left, string $right): int => strcasecmp($left, $right));

        return $groups;
    }

    /**
     * @param  array<string, list<array{state: string}>>  $groups
     */
    private static function pickServiceKey(array $groups, string $service): string
    {
        if ($service !== '' && isset($groups[$service])) {
            return $service;
        }

        foreach ($groups as $key => $containers) {
            foreach ($containers as $container) {
                if ($container['state'] === 'running') {
                    return $key;
                }
            }
        }

        return (string) array_key_first($groups);
    }

    /**
     * @param  array<string, list<array{state: string}>>  $groups
     * @return list<array{value: string, label: string, dot: string, description?: string, state?: string}>
     */
    private static function serviceOptions(array $groups): array
    {
        $options = [];
        foreach ($groups as $key => $containers) {
            $states = array_column($containers, 'state');
            $option = [
                'value' => $key,
                'label' => $key,
                'dot' => self::serviceDot($states),
            ];
            $state = self::serviceStateLabel($states);
            if ($state !== null) {
                $option['state'] = $state;
            }
            if (count($containers) > 1) {
                $option['description'] = count($containers).' containers';
            }
            $options[] = $option;
        }

        return $options;
    }

    /**
     * @param  list<string>  $states
     */
    private static function serviceDot(array $states): string
    {
        $allRunning = true;
        $anyLive = false;
        foreach ($states as $state) {
            if ($state !== 'running') {
                $allRunning = false;
            }
            if (in_array($state, ['running', 'starting', 'restarting', 'degraded'], true)) {
                $anyLive = true;
            }
        }
        if ($allRunning) {
            return 'success';
        }
        if (! $anyLive) {
            return 'error';
        }

        return 'warning';
    }

    /**
     * A running service needs no caption. Anything else is named: Stopped when
     * nothing is running, the transitional state when that is the only problem,
     * and Degraded when the containers disagree.
     *
     * @param  list<string>  $states
     */
    private static function serviceStateLabel(array $states): ?string
    {
        $problems = [];
        $anyLive = false;
        foreach ($states as $state) {
            $state = strtolower($state);
            if (in_array($state, ['running', 'starting', 'restarting', 'degraded'], true)) {
                $anyLive = true;
            }
            if ($state !== 'running') {
                $problems[$state] = true;
            }
        }
        if ($problems === []) {
            return null;
        }

        $problemStates = array_keys($problems);
        if ($problemStates === ['starting']) {
            return 'Starting';
        }
        if ($problemStates === ['restarting']) {
            return 'Restarting';
        }
        if ($problemStates === ['degraded']) {
            return 'Degraded';
        }
        if (! $anyLive) {
            return 'Stopped';
        }

        return 'Degraded';
    }

    /**
     * @param  list<array{name: string, server: string, serverName: string, serverIndex: int}>  $containers
     * @return list<array{server: string, container: string, label: string}>
     */
    private static function sourcesFor(array $containers): array
    {
        usort($containers, function (array $left, array $right): int {
            $byServer = $left['serverIndex'] <=> $right['serverIndex'];

            return $byServer !== 0 ? $byServer : strcmp($left['name'], $right['name']);
        });

        $serverCounts = [];
        foreach ($containers as $container) {
            $serverCounts[$container['server']] = ($serverCounts[$container['server']] ?? 0) + 1;
        }
        $multipleServers = count($serverCounts) > 1;
        $sources = [];
        foreach ($containers as $container) {
            $sources[] = [
                'server' => $container['server'],
                'container' => $container['name'],
                'label' => self::sourceLabel($container, $multipleServers, $serverCounts[$container['server']], count($containers)),
            ];
        }

        return self::uniqueLabels($sources);
    }

    /**
     * @param  array{name: string, server: string, serverName: string}  $container
     */
    private static function sourceLabel(array $container, bool $multipleServers, int $onServer, int $total): string
    {
        if ($total < 2) {
            return $container['name'];
        }

        $parts = [];
        if ($multipleServers) {
            $name = trim($container['serverName']);
            $parts[] = $name !== '' ? $name : $container['server'];
        }
        if ($onServer > 1 || ! $multipleServers) {
            $parts[] = $container['name'];
        }

        return implode(' ', $parts);
    }

    /**
     * @param  list<array{server: string, container: string, label: string}>  $sources
     * @return list<array{server: string, container: string, label: string}>
     */
    private static function uniqueLabels(array $sources): array
    {
        $counts = array_count_values(array_column($sources, 'label'));
        foreach ($sources as $index => $source) {
            if ($counts[$source['label']] < 2) {
                continue;
            }
            if (! str_contains($source['label'], $source['container'])) {
                $sources[$index]['label'] = trim($source['label'].' '.$source['container']);
            }
        }

        $counts = array_count_values(array_column($sources, 'label'));
        foreach ($sources as $index => $source) {
            if (($counts[$source['label']] ?? 0) < 2) {
                continue;
            }
            $sources[$index]['label'] = $source['label'].' '.substr($source['server'], 0, 8);
        }

        return $sources;
    }

    /**
     * @param  list<array{server: string, container: string}>  $sources
     */
    private static function streamKey(array $sources): string
    {
        return implode('|', array_map(
            fn (array $source): string => $source['server'].':'.$source['container'],
            $sources,
        ));
    }
}
