<?php

namespace App\Console\Commands;

use App\Actions\Development\SeedDevelopmentQemuServer;
use App\Actions\Development\StartDevelopmentInstanceDatabase;
use Illuminate\Console\Command;
use Throwable;

class SeedDevelopmentQemuServerCommand extends Command
{
    protected $signature = 'dev:qemu:seed {profile : Profile key from config/development-qemu.php} {--keep-others} {--as-localhost : Use the VM for the localhost server (id 0)} {--ip= : SSH host instead of the profile IP} {--port=22 : SSH port}';

    protected $description = 'Seed one development QEMU server in the Coolify database';

    public function handle(): int
    {
        if (! isDev()) {
            $this->error('This command may only run in development mode.');

            return self::FAILURE;
        }

        $server = SeedDevelopmentQemuServer::run($this->argument('profile'), ! $this->option('keep-others'), (bool) $this->option('as-localhost'), $this->option('ip') ?: null, (int) $this->option('port'));
        $this->info("Seeded {$server->name} at {$server->ip}.");

        if ($this->option('as-localhost')) {
            try {
                StartDevelopmentInstanceDatabase::run($server);
                $this->info('Started the coolify-db container for instance backups.');
            } catch (Throwable $e) {
                $this->warn("Could not start the coolify-db container for instance backups: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
