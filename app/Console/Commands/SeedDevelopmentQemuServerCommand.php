<?php

namespace App\Console\Commands;

use App\Actions\Development\SeedDevelopmentQemuServer;
use Illuminate\Console\Command;

class SeedDevelopmentQemuServerCommand extends Command
{
    protected $signature = 'dev:qemu:seed {profile : Profile key from config/development-qemu.php} {--keep-others}';

    protected $description = 'Seed one development QEMU server in the Coolify database';

    public function handle(): int
    {
        if (! isDev()) {
            $this->error('This command may only run in development mode.');

            return self::FAILURE;
        }

        $server = SeedDevelopmentQemuServer::run($this->argument('profile'), ! $this->option('keep-others'));
        $this->info("Seeded {$server->name} at {$server->ip}.");

        return self::SUCCESS;
    }
}
