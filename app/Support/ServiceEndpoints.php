<?php

namespace App\Support;

use App\Models\Application;
use App\Models\ServiceApplication;
use Illuminate\Support\Str;

/**
 * Endpoints of service images that serve several URLs from one container. The first endpoint is the primary one.
 */
class ServiceEndpoints
{
    /**
     * @return list<ServiceEndpoint>
     */
    public static function for(ServiceApplication|Application $resource): array
    {
        $type = $resource->serviceType();

        return match (true) {
            $type === null => [],
            $type->contains(['minio', 'pgsty/silo']) => self::minio(),
            $type->contains('logto') => self::logto(),
            $type->contains('garage') => self::garage(),
            default => [],
        };
    }

    /**
     * Endpoints of a container that declares several magic URL names, each with one port (for example
     * SERVICE_URL_BACKEND_3210 and SERVICE_URL_SITE_3211). Without endpoints, all names share the first domain
     * and the proxy routes only one port. A name without a port or with several ports keeps that behavior.
     *
     * @return list<ServiceEndpoint>
     */
    public static function fromCompose(ServiceApplication $application): array
    {
        $service = $application->service;
        $compose = $service?->docker_compose_raw ? parseDockerComposeYaml($service->docker_compose_raw) : null;
        $services = collect(data_get($compose, 'services', []));
        if (! $services->has($application->name)) {
            return [];
        }

        $declarations = convertToKeyValueCollection(
            collect(data_get($services, [$application->name, 'environment'], []))
                ->merge(data_get($services, [$application->name, 'build', 'args'], []))
        );
        $names = [];
        foreach ($declarations as $key => $value) {
            $key = (string) $key;
            if (! str_starts_with($key, 'SERVICE_URL_') && ! str_starts_with($key, 'SERVICE_FQDN_')) {
                continue;
            }
            if (isCopiedServiceUrlDeclaration($service, $services, $application, $key)) {
                continue;
            }
            $parsed = parseServiceEnvironmentVariable($key);
            $name = Str::after($key, str_starts_with($key, 'SERVICE_URL_') ? 'SERVICE_URL_' : 'SERVICE_FQDN_');
            $name = $parsed['has_port'] ? Str::beforeLast($name, '_') : $name;
            $names[$parsed['service_name']] ??= ['name' => $name, 'ports' => [], 'path' => ''];
            if ($parsed['port'] !== null) {
                $names[$parsed['service_name']]['ports'][] = (int) $parsed['port'];
            }
            if (str_starts_with((string) $value, '/') && (string) $value !== '/') {
                $names[$parsed['service_name']]['path'] = (string) $value;
            }
        }

        $endpoints = [];
        foreach ($names as $key => $declaration) {
            $ports = array_values(array_unique($declaration['ports']));
            if (count($ports) !== 1) {
                return [];
            }
            $endpoints[] = new ServiceEndpoint(
                key: $key,
                label: Str::headline($key),
                envKey: "SERVICE_URL_{$declaration['name']}",
                port: $ports[0],
                urlPrefix: str_replace('_', '-', $key).'-',
                forceHttps: false,
                path: $declaration['path'],
            );
        }

        return count($endpoints) > 1 ? $endpoints : [];
    }

    public static function isEndpointVariable(string $key): bool
    {
        return collect([...self::minio(), ...self::logto(), ...self::garage()])
            ->contains(fn (ServiceEndpoint $endpoint): bool => $endpoint->envKey === $key);
    }

    /**
     * @return list<ServiceEndpoint>
     */
    private static function minio(): array
    {
        return [
            new ServiceEndpoint('console', 'Console', 'MINIO_BROWSER_REDIRECT_URL', 9001, 'console-', true),
            new ServiceEndpoint('s3', 'S3 API', 'MINIO_SERVER_URL', 9000, 'minio-', true),
        ];
    }

    /**
     * @return list<ServiceEndpoint>
     */
    private static function logto(): array
    {
        return [
            new ServiceEndpoint('app', 'App', 'LOGTO_ENDPOINT', 3001, 'logto-', false),
            new ServiceEndpoint('admin', 'Admin', 'LOGTO_ADMIN_ENDPOINT', 3002, 'logto-admin-', false),
        ];
    }

    /**
     * @return list<ServiceEndpoint>
     */
    private static function garage(): array
    {
        return [
            new ServiceEndpoint('s3', 'S3 API', 'GARAGE_S3_API_URL', 3900, 's3-', true),
            new ServiceEndpoint('web', 'Website', 'GARAGE_WEB_URL', 3902, 'web-', true),
            new ServiceEndpoint('admin', 'Admin API', 'GARAGE_ADMIN_URL', 3903, 'admin-', true),
        ];
    }
}
