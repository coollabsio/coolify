<?php

namespace App\Console\Commands;

use App\Actions\Migration\RunInstanceMigration;
use Illuminate\Console\Command;

class MigrateInstance extends Command
{
    protected $signature = 'coolify:migrate-instance
                            {--target-ip= : Target VM IP or hostname}
                            {--target-user=root : SSH user}
                            {--target-port=22 : SSH port}
                            {--target-private-key-id= : Private key ID from Coolify}
                            {--dry-run : Validate SSH and preflight only}';

    protected $description = 'Migrate this Coolify instance (dashboard + all resources/volumes) to a new VM.';

    public function handle(RunInstanceMigration $migration): int
    {
        if (! $this->option('target-ip')) {
            $this->error('--target-ip is required.');

            return 1;
        }

        return $migration->asCommand($this);
    }
}
