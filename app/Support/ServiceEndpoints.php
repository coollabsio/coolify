<?php

namespace App\Support;

use App\Models\Application;
use App\Models\ServiceApplication;

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
            $type->contains(['minio', 'pgsty/silo']) => [
                new ServiceEndpoint('console', 'Console', 'MINIO_BROWSER_REDIRECT_URL', 9001, 'console-', true),
                new ServiceEndpoint('s3', 'S3 API', 'MINIO_SERVER_URL', 9000, 'minio-', true),
            ],
            $type->contains('logto') => [
                new ServiceEndpoint('app', 'App', 'LOGTO_ENDPOINT', 3001, 'logto-', false),
                new ServiceEndpoint('admin', 'Admin', 'LOGTO_ADMIN_ENDPOINT', 3002, 'logto-admin-', false),
            ],
            $type->contains('garage') => [
                new ServiceEndpoint('s3', 'S3 API', 'GARAGE_S3_API_URL', 3900, 's3-', true),
                new ServiceEndpoint('web', 'Website', 'GARAGE_WEB_URL', 3902, 'web-', true),
                new ServiceEndpoint('admin', 'Admin API', 'GARAGE_ADMIN_URL', 3903, 'admin-', true),
            ],
            default => [],
        };
    }
}
