<?php

namespace App\Support;

class ContainerInfo
{
    /**
     * @param  array<int, array<string, mixed>>|array<string, mixed>|string|null  $inspect
     * @return array{id: ?string, name: ?string, image: ?string, created_at: ?string, started_at: ?string, ipv4_addresses: array<int, string>, ipv6_addresses: array<int, string>}|null
     */
    public static function fromDockerInspect(array|string|null $inspect): ?array
    {
        if (blank($inspect)) {
            return null;
        }

        if (is_string($inspect)) {
            $decodedInspect = json_decode($inspect, true);
            if (! is_array($decodedInspect)) {
                return null;
            }
            $inspect = $decodedInspect;
        }

        $container = array_is_list($inspect) ? ($inspect[0] ?? null) : $inspect;
        if (! is_array($container) || blank($container)) {
            return null;
        }

        return [
            'id' => data_get($container, 'Id'),
            'name' => str((string) data_get($container, 'Name'))->ltrim('/')->toString() ?: null,
            'image' => data_get($container, 'Config.Image') ?: data_get($container, 'Image'),
            'created_at' => data_get($container, 'Created'),
            'started_at' => self::filledTimestamp(data_get($container, 'State.StartedAt')),
            'ipv4_addresses' => self::networkAddresses($container, 'IPAddress'),
            'ipv6_addresses' => self::networkAddresses($container, 'GlobalIPv6Address'),
        ];
    }

    public static function inspectCommandForApplication(int $applicationId): string
    {
        return self::inspectCommandForLabel("coolify.applicationId={$applicationId}");
    }

    public static function inspectCommandForServiceSub(int $serviceSubId): string
    {
        return self::inspectCommandForLabel("coolify.service.subId={$serviceSubId}");
    }

    private static function inspectCommandForLabel(string $label): string
    {
        return "CONTAINER_ID=\$(docker ps --filter='label={$label}' --format '{{.ID}}' | head -n 1); if [ -z \"\$CONTAINER_ID\" ]; then CONTAINER_ID=\$(docker ps -a --filter='label={$label}' --format '{{.ID}}' | head -n 1); fi; if [ -n \"\$CONTAINER_ID\" ]; then docker inspect \"\$CONTAINER_ID\"; fi";
    }

    private static function filledTimestamp(mixed $value): ?string
    {
        if (blank($value) || $value === '0001-01-01T00:00:00Z') {
            return null;
        }

        return (string) $value;
    }

    /**
     * @param  array<string, mixed>  $container
     * @return array<int, string>
     */
    private static function networkAddresses(array $container, string $field): array
    {
        $networks = data_get($container, 'NetworkSettings.Networks', []);
        if (! is_array($networks)) {
            return [];
        }

        return collect($networks)
            ->pluck($field)
            ->filter(fn ($address) => filled($address))
            ->map(fn ($address) => (string) $address)
            ->unique()
            ->values()
            ->all();
    }
}
