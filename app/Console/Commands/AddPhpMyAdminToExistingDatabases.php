<?php

namespace App\Console\Commands;

use App\Actions\Database\RestartDatabase;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMysql;
use Illuminate\Console\Command;

class AddPhpMyAdminToExistingDatabases extends Command
{
    protected $signature = 'database:add-phpmyadmin 
                            {--all : Agregar phpMyAdmin a todas las bases de datos MySQL/MariaDB}
                            {--uuid= : UUID específico de la base de datos a migrar}';

    protected $description = 'Agrega phpMyAdmin a bases de datos MySQL/MariaDB existentes';

    public function handle(): int
    {
        $this->info('🔍 Buscando bases de datos MySQL/MariaDB...');

        $databases = collect();

        if ($this->option('uuid')) {
            // Buscar base de datos específica
            $mariadb = StandaloneMariadb::where('uuid', $this->option('uuid'))->first();
            $mysql = StandaloneMysql::where('uuid', $this->option('uuid'))->first();

            if ($mariadb) {
                $databases->push($mariadb);
            } elseif ($mysql) {
                $databases->push($mysql);
            } else {
                $this->error("❌ No se encontró ninguna base de datos con UUID: {$this->option('uuid')}");
                return Command::FAILURE;
            }
        } elseif ($this->option('all')) {
            // Buscar todas las bases de datos MySQL/MariaDB
            $mariadbDatabases = StandaloneMariadb::all();
            $mysqlDatabases = StandaloneMysql::all();
            $databases = $mariadbDatabases->merge($mysqlDatabases);
        } else {
            $this->error('❌ Debes especificar --all o --uuid=<uuid>');
            $this->info('');
            $this->info('Ejemplos:');
            $this->info('  php artisan database:add-phpmyadmin --all');
            $this->info('  php artisan database:add-phpmyadmin --uuid=abc123-def456-...');
            return Command::FAILURE;
        }

        if ($databases->isEmpty()) {
            $this->warn('⚠️  No se encontraron bases de datos MySQL/MariaDB.');
            return Command::SUCCESS;
        }

        $this->info("📊 Se encontraron {$databases->count()} base(s) de datos.");
        $this->info('');

        $successCount = 0;
        $failedCount = 0;

        foreach ($databases as $database) {
            try {
                $this->info("🔄 Procesando: {$database->name} ({$database->uuid})...");

                // Verificar si el servidor es funcional
                $server = $database->destination->server;
                if (! $server->isFunctional()) {
                    $this->warn("   ⚠️  Servidor no funcional, saltando...");
                    $failedCount++;
                    continue;
                }

                // Verificar si ya tiene phpMyAdmin
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

                // Reiniciar la base de datos (esto agregará phpMyAdmin automáticamente)
                $this->info("   🔄 Reiniciando base de datos para agregar phpMyAdmin...");
                RestartDatabase::run($database);

                $this->info("   ✅ phpMyAdmin agregado exitosamente!");
                $successCount++;
            } catch (\Throwable $e) {
                $this->error("   ❌ Error: {$e->getMessage()}");
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
