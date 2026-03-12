<?php

namespace App\Console\Commands;

use App\Actions\Database\RestartDatabase;
use App\Actions\Service\RestartService;
use App\Models\Service;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMysql;
use Illuminate\Console\Command;
use Symfony\Component\Yaml\Yaml;

class AddPhpMyAdminToExistingDatabases extends Command
{
    protected $signature = 'database:add-phpmyadmin 
                            {--all : Agregar phpMyAdmin a todas las bases de datos MySQL/MariaDB}
                            {--uuid= : UUID específico de la base de datos o servicio a migrar}';

    protected $description = 'Agrega phpMyAdmin a bases de datos MySQL/MariaDB existentes (standalone y dentro de servicios)';

    public function handle(): int
    {
        $this->info('🔍 Buscando bases de datos MySQL/MariaDB...');

        $databases = collect();
        $services = collect();

        if ($this->option('uuid')) {
            // Buscar base de datos específica o servicio
            $mariadb = StandaloneMariadb::where('uuid', $this->option('uuid'))->first();
            $mysql = StandaloneMysql::where('uuid', $this->option('uuid'))->first();
            $service = Service::where('uuid', $this->option('uuid'))->first();

            if ($mariadb) {
                $databases->push($mariadb);
            } elseif ($mysql) {
                $databases->push($mysql);
            } elseif ($service) {
                $services->push($service);
            } else {
                $this->error("❌ No se encontró ninguna base de datos o servicio con UUID: {$this->option('uuid')}");
                return Command::FAILURE;
            }
        } elseif ($this->option('all')) {
            // Buscar todas las bases de datos MySQL/MariaDB standalone
            $mariadbDatabases = StandaloneMariadb::all();
            $mysqlDatabases = StandaloneMysql::all();
            $databases = $mariadbDatabases->merge($mysqlDatabases);
            
            // Buscar servicios con MySQL/MariaDB en su docker-compose
            $allServices = Service::all();
            foreach ($allServices as $service) {
                if ($this->serviceHasMysqlOrMariadb($service)) {
                    $services->push($service);
                }
            }
        } else {
            $this->error('❌ Debes especificar --all o --uuid=<uuid>');
            $this->info('');
            $this->info('Ejemplos:');
            $this->info('  php artisan database:add-phpmyadmin --all');
            $this->info('  php artisan database:add-phpmyadmin --uuid=abc123-def456-...');
            return Command::FAILURE;
        }

        $totalCount = $databases->count() + $services->count();
        if ($totalCount === 0) {
            $this->warn('⚠️  No se encontraron bases de datos MySQL/MariaDB.');
            return Command::SUCCESS;
        }

        $this->info("📊 Se encontraron {$databases->count()} base(s) de datos standalone y {$services->count()} servicio(s) con MySQL/MariaDB.");
        $this->info('');

        $successCount = 0;
        $failedCount = 0;

        // Procesar bases de datos standalone
        foreach ($databases as $database) {
            try {
                $this->info("🔄 Procesando base de datos: {$database->name} ({$database->uuid})...");

                $server = $database->destination->server;
                if (! $server->isFunctional()) {
                    $this->warn("   ⚠️  Servidor no funcional, saltando...");
                    $failedCount++;
                    continue;
                }

                $phpmyadminContainer = $database->uuid.'-phpmyadmin';
                $escapedContainer = escapeshellarg($phpmyadminContainer);
                $checkCommand = "docker ps -a --filter name=^{$escapedContainer}$ --format '{{.Names}}'";
                if ($server->isNonRoot()) {
                    $checkCommand = "sudo {$checkCommand}";
                }

                $containerExists = instant_remote_process([$checkCommand], $server, false);
                if (!empty(trim($containerExists))) {
                    $this->info("   ✓ phpMyAdmin ya existe, saltando...");
                    continue;
                }

                $this->info("   🔄 Reiniciando base de datos para agregar phpMyAdmin...");
                RestartDatabase::run($database);

                $this->info("   ✅ phpMyAdmin agregado exitosamente!");
                $successCount++;
            } catch (\Throwable $e) {
                $this->error("   ❌ Error: {$e->getMessage()}");
                $failedCount++;
            }
        }

        // Procesar servicios con MySQL/MariaDB
        foreach ($services as $service) {
            try {
                $this->info("🔄 Procesando servicio: {$service->name} ({$service->uuid})...");

                $server = $service->server;
                if (! $server->isFunctional()) {
                    $this->warn("   ⚠️  Servidor no funcional, saltando...");
                    $failedCount++;
                    continue;
                }

                // Verificar si ya tiene phpMyAdmin
                if ($this->serviceHasPhpMyAdmin($service)) {
                    $this->info("   ✓ phpMyAdmin ya existe en este servicio, saltando...");
                    continue;
                }

                // Agregar phpMyAdmin al docker-compose del servicio
                $this->info("   🔄 Agregando phpMyAdmin al servicio...");
                $this->addPhpMyAdminToService($service);

                // Reiniciar el servicio para aplicar los cambios
                $this->info("   🔄 Reiniciando servicio...");
                RestartService::run($service);

                $this->info("   ✅ phpMyAdmin agregado exitosamente!");
                $successCount++;
            } catch (\Throwable $e) {
                $this->error("   ❌ Error: {$e->getMessage()}");
                $this->error("   📋 Trace: {$e->getTraceAsString()}");
                $failedCount++;
            }
        }

        $this->info('');
        $this->info('═══════════════════════════════════════════════════════');
        $this->info("✅ Completado: {$successCount} exitosa(s), {$failedCount} fallida(s)");
        $this->info('═══════════════════════════════════════════════════════');

        return $failedCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function serviceHasMysqlOrMariadb(Service $service): bool
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

    private function serviceHasPhpMyAdmin(Service $service): bool
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
