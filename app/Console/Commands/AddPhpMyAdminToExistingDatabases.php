<?php

namespace App\Console\Commands;

use App\Actions\Database\RestartDatabase;
use App\Actions\Service\EnsurePhpMyAdminForService;
use App\Actions\Service\RestartService;
use App\Models\Service;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMysql;
use Illuminate\Console\Command;

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
                if (EnsurePhpMyAdminForService::make()->serviceHasMysqlOrMariadb($service)) {
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
                if (EnsurePhpMyAdminForService::make()->serviceHasPhpMyAdmin($service)) {
                    $this->info("   ✓ phpMyAdmin ya existe en este servicio, saltando...");
                    continue;
                }

                // Agregar phpMyAdmin al docker-compose del servicio
                $this->info("   🔄 Agregando phpMyAdmin al servicio...");
                $added = EnsurePhpMyAdminForService::run($service);
                
                if (!$added) {
                    $this->warn("   ⚠️  No se pudo agregar phpMyAdmin (puede que ya exista o no tenga MySQL/MariaDB)");
                    continue;
                }

                // Reiniciar el servicio para aplicar los cambios
                $this->info("   🔄 Reiniciando servicio...");
                RestartService::run($service, pullLatestImages: false);

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

}
