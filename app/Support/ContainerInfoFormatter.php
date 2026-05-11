<?php

namespace App\Support;

class ContainerInfoFormatter
{
    public static function fromDockerInspect(array $inspect): array
    {
        $id = (string) data_get($inspect, 'Id', '');
        $name = ltrim((string) data_get($inspect, 'Name', ''), '/');

        return [
            'id' => $id,
            'id_short' => $id !== '' ? substr($id, 0, 12) : null,
            'name' => $name !== '' ? $name : null,
            'image' => data_get($inspect, 'Config.Image'),
            'image_id' => data_get($inspect, 'Image'),
            'status' => data_get($inspect, 'State.Status'),
            'created_at' => self::validDockerTimestamp(data_get($inspect, 'Created')),
            'started_at' => self::validDockerTimestamp(data_get($inspect, 'State.StartedAt')),
            'finished_at' => self::validDockerTimestamp(data_get($inspect, 'State.FinishedAt')),
            'restart_count' => data_get($inspect, 'RestartCount'),
            'networks' => self::networks($inspect),
        ];
    }

    public static function networks(array $inspect): array
    {
        $networks = data_get($inspect, 'NetworkSettings.Networks', []);
        if (! is_array($networks)) {
            return [];
        }

        return collect($networks)
            ->filter(fn ($network) => is_array($network))
            ->map(fn (array $network, string $name) => [
                'name' => $name,
                'ipv4' => data_get($network, 'IPAddress'),
                'ipv6' => data_get($network, 'GlobalIPv6Address'),
                'mac_address' => data_get($network, 'MacAddress'),
                'network_id' => data_get($network, 'NetworkID'),
            ])
            ->values()
            ->all();
    }

    private static function validDockerTimestamp(?string $value): ?string
    {
        if (blank($value) || str_starts_with($value, '0001-01-01')) {
            return null;
        }

        return $value;
    }
}
