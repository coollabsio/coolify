<?php

namespace App\Actions\Service;

use App\Models\Service;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Yaml\Yaml;

class EnsurePhpMyAdminForService
{
    use AsAction;

    public function handle(Service $service): bool
    {
        // Verificar si el servicio tiene MySQL/MariaDB
        if (! $this->serviceHasMysqlOrMariadb($service)) {
            return false;
        }

        // Verificar si ya tiene phpMyAdmin
        if ($this->serviceHasPhpMyAdmin($service)) {
            return false;
        }

        // Agregar phpMyAdmin al servicio
        try {
            $this->addPhpMyAdminToService($service);
            return true;
        } catch (\Throwable $e) {
            \Log::error('Failed to add phpMyAdmin to service', [
                'service_id' => $service->id,
                'service_uuid' => $service->uuid,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function serviceHasMysqlOrMariadb(Service $service): bool
    {
        if (empty($service->docker_compose_raw)) {
            return false;
        }

        try {
            $compose = Yaml::parse($service->docker_compose_raw);
            $services = data_get($compose, 'services', []);

            foreach ($services as $serviceName => $serviceConfig) {
                $image = str(data_get($serviceConfig, 'image', ''))->lower();
                $serviceNameLower = str($serviceName)->lower();
                
                if ($image->contains('mysql') || $image->contains('mariadb') ||
                    $serviceNameLower->contains('mysql') || $serviceNameLower->contains('mariadb')) {
                    // Excluir phpmyadmin
                    if (! $serviceNameLower->contains('phpmyadmin')) {
                        return true;
                    }
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    public function serviceHasPhpMyAdmin(Service $service): bool
    {
        if (empty($service->docker_compose_raw)) {
            return false;
        }

        try {
            $compose = Yaml::parse($service->docker_compose_raw);
            $services = data_get($compose, 'services', []);

            foreach ($services as $serviceName => $serviceConfig) {
                $image = str(data_get($serviceConfig, 'image', ''))->lower();
                $serviceNameLower = str($serviceName)->lower();
                
                if ($serviceNameLower->contains('phpmyadmin') || $image->contains('phpmyadmin')) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    private function addPhpMyAdminToService(Service $service): void
    {
        if (empty($service->docker_compose_raw)) {
            throw new \Exception('El servicio no tiene docker-compose configurado');
        }

        $compose = Yaml::parse($service->docker_compose_raw);
        $services = data_get($compose, 'services', []);

        // Encontrar el servicio MySQL/MariaDB
        $dbServiceName = null;
        $dbServiceConfig = null;
        $rootPassword = null;

        foreach ($services as $serviceName => $serviceConfig) {
            $image = str(data_get($serviceConfig, 'image', ''))->lower();
            $serviceNameLower = str($serviceName)->lower();
            
            if (($image->contains('mysql') || $image->contains('mariadb') ||
                 $serviceNameLower->contains('mysql') || $serviceNameLower->contains('mariadb')) &&
                ! $serviceNameLower->contains('phpmyadmin')) {
                
                $dbServiceName = $serviceName;
                $dbServiceConfig = $serviceConfig;
                
                // Obtener la contraseña root desde las variables de entorno
                $env = data_get($serviceConfig, 'environment', []);
                foreach ($env as $envVar) {
                    if (is_string($envVar) && str_contains($envVar, '=')) {
                        [$key, $value] = explode('=', $envVar, 2);
                        if ($key === 'MYSQL_ROOT_PASSWORD' || $key === 'MARIADB_ROOT_PASSWORD') {
                            $rootPassword = $value;
                            break;
                        }
                    } elseif (is_array($envVar)) {
                        foreach ($envVar as $k => $v) {
                            if ($k === 'MYSQL_ROOT_PASSWORD' || $k === 'MARIADB_ROOT_PASSWORD') {
                                $rootPassword = $v;
                                break 2;
                            }
                        }
                    }
                }
                break;
            }
        }

        if (! $dbServiceName) {
            throw new \Exception('No se encontró ningún servicio MySQL/MariaDB en el docker-compose');
        }

        // Generar nombre y configuración para phpMyAdmin
        $phpmyadminServiceName = $dbServiceName.'-phpmyadmin';
        $phpmyadminVolumeName = $service->uuid.'-phpmyadmin-config';
        
        // Obtener la red del servicio
        $networks = data_get($dbServiceConfig, 'networks', []);
        if (empty($networks)) {
            // Si no tiene redes específicas, usar la red del servicio
            $networks = [$service->uuid];
        } else {
            $networks = is_array($networks) ? array_keys($networks) : [$networks];
        }
        $network = $networks[0] ?? $service->uuid;

        // Generar URL para phpMyAdmin usando el mismo formato que otros servicios
        $server = $service->server;
        // Usar un nombre más corto para evitar URLs muy largas
        $phpmyadminRandom = substr($service->uuid, 0, 8).'-phpmyadmin';
        $phpmyadminUrl = generateUrl($server, $phpmyadminRandom);

        // Agregar phpMyAdmin al docker-compose
        $compose['services'][$phpmyadminServiceName] = [
            'image' => 'lscr.io/linuxserver/phpmyadmin:latest',
            'container_name' => "{$phpmyadminServiceName}-{$service->uuid}",
            'environment' => [
                'SERVICE_URL_PHPMYADMIN='.$phpmyadminUrl,
                'PUID=1000',
                'PGID=1000',
                'TZ=Europe/Madrid',
                'PMA_ARBITRARY=1',
                'PMA_ABSOLUTE_URI='.$phpmyadminUrl,
                'PMA_HOST='.$dbServiceName,
                'PMA_USER=root',
                'PMA_PASSWORD='.($rootPassword ?? '${SERVICE_PASSWORD_MYSQLROOT}'),
            ],
            'networks' => [
                $network => null,
            ],
            'volumes' => [
                "{$phpmyadminVolumeName}:/config",
            ],
            'depends_on' => [
                $dbServiceName => [
                    'condition' => 'service_healthy',
                ],
            ],
            'healthcheck' => [
                'test' => ['CMD', 'curl', '-f', 'http://127.0.0.1:80'],
                'interval' => '2s',
                'timeout' => '10s',
                'retries' => 15,
            ],
        ];

        // Agregar volumen si no existe
        if (!isset($compose['volumes'])) {
            $compose['volumes'] = [];
        }
        $compose['volumes'][$phpmyadminVolumeName] = [];

        // Guardar el docker-compose modificado
        $service->docker_compose_raw = Yaml::dump($compose, 10);
        $service->save();
        
        // Parsear el servicio para que se creen las variables de entorno y aplicaciones
        $service->parse();
        
        // Aplicar prerrequisitos de aplicaciones (esto crea las ServiceApplications)
        applyServiceApplicationPrerequisites($service);
        
        // Guardar configuración del servicio para que se generen los archivos
        $service->saveComposeConfigs();
        
        // Regenerar proxy para que detecte el nuevo phpMyAdmin
        $server = $service->server;
        if ($server && $server->proxyType() !== 'NONE') {
            $server->setupDynamicProxyConfiguration();
        }
    }
}
