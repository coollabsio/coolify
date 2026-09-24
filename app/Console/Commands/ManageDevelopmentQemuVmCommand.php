<?php

namespace App\Console\Commands;

use App\Actions\Development\ManageDevelopmentQemuVm;
use Illuminate\Console\Command;

use function Laravel\Prompts\multiselect;

class ManageDevelopmentQemuVmCommand extends Command
{
    protected $signature = 'dev:qemu {profiles?* : Profile keys from config/development-qemu.php} {--as-localhost : Use the VM for the localhost server (id 0)}';

    protected $description = 'Recreate selected development QEMU VMs and seed their Coolify servers';

    public function handle(): int
    {
        if (! isDev()) {
            $this->error('This command may only run in development mode.');

            return self::FAILURE;
        }

        $profiles = config('development-qemu.profiles');
        $profileNames = $this->argument('profiles') ?: multiselect(
            label: 'Which QEMU servers should be started and seeded?',
            options: collect($profiles)->mapWithKeys(fn (array $profile, string $key) => [$key => $profile['label']])->all(),
            required: true,
        );

        if ($this->option('as-localhost') && count($profileNames) !== 1) {
            $this->error('Select exactly one QEMU profile for localhost.');

            return self::FAILURE;
        }

        ManageDevelopmentQemuVm::run($profileNames, (bool) $this->option('as-localhost'));

        foreach ($profileNames as $profileName) {
            $profile = $profiles[$profileName];
            $this->info("Started and seeded {$profile['label']} at {$profile['ip']}.");
        }

        return self::SUCCESS;
    }
}
