<?php

namespace App\Console\Commands;

use App\Actions\Development\SeedDevelopmentQemuServer;
use Illuminate\Console\Command;

class SeedDevelopmentQemuServerCommand extends Command
{
    protected $signature = 'dev:qemu:seed {profile : Profile key from config/development-qemu.php} {--keep-others} {--as-localhost : Use the VM for the localhost server (id 0)}';

    protected $description = 'Seed one development QEMU server in the Coolify database';

    public function handle(): int
    {
        if (! isDev()) {
            $this->error('This command may only run in development mode.');

            return self::FAILURE;
        }

        $server = SeedDevelopmentQemuServer::run($this->argument('profile'), ! $this->option('keep-others'), (bool) $this->option('as-localhost'));
        $this->info("Seeded {$server->name} at {$server->ip}.");

        return self::SUCCESS;
    }
}
