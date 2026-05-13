<?php

namespace App\Support;

use Carbon\Carbon;

class ContainerInfoPresenter
{
    public static function fromInspect(array $inspect, string $fallbackContainerName): array
    {
        $networks = collect(data_get($inspect, 'NetworkSettings.Networks', []))
            ->map(function ($network, string $networkName) {
                return [
                    'name' => $networkName,
                    'ipv4' => data_get($network, 'IPAddress') ?: null,
                    'ipv6' => data_get($network, 'GlobalIPv6Address') ?: null,
                    'mac' => data_get($network, 'MacAddress') ?: null,
                    'gateway' => data_get($network, 'Gateway') ?: null,
                ];
            })
            ->values()
            ->all();

        return [
            'container_id' => data_get($inspect, 'Id') ?: $fallbackContainerName,
            'container_name' => ltrim((string) data_get($inspect, 'Name', $fallbackContainerName), '/'),
            'hostname' => data_get($inspect, 'Config.Hostname') ?: null,
            'status' => data_get($inspect, 'State.Status') ?: null,
            'image_reference' => data_get($inspect, 'Config.Image') ?: null,
            'image_hash' => data_get($inspect, 'Image') ?: null,
            'image_digest' => data_get($inspect, 'RepoDigests.0') ?: null,
            'created_at' => self::formatDate(data_get($inspect, 'Created')),
            'started_at' => self::formatDate(data_get($inspect, 'State.StartedAt')),
            'restart_count' => (int) (data_get($inspect, 'RestartCount') ?? data_get($inspect, 'State.RestartCount') ?? 0),
            'networks' => $networks,
        ];
    }

    private static function formatDate(?string $value): ?array
    {
        if (blank($value) || str_starts_with($value, '0001-01-01')) {
            return null;
        }

        try {
            $date = Carbon::parse($value);

            return [
                'iso' => $date->toIso8601String(),
                'display' => $date->format('Y-m-d H:i:s T'),
                'human' => $date->diffForHumans(),
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
