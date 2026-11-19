<?php

namespace App\Console\Commands;

use App\Actions\Development\ManageDevelopmentQemuVm;
use Illuminate\Console\Command;

use function Laravel\Prompts\multiselect;

class ManageDevelopmentQemuVmCommand extends Command
{
    protected $signature = 'dev:qemu {profiles?* : Profile keys from config/development-qemu.php} {--as-localhost : Use the VM for the localhost server (id 0)} {--fresh : Destroy and recreate the selected VMs instead of reusing them} {--gpu : Pass the NVIDIA GPU of the host through to the VM and install its driver}';

    protected $description = 'Start (or create) selected development QEMU VMs and seed their Coolify servers';

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

        if ($this->option('gpu')) {
            $profile = $profiles[$profileNames[0]] ?? null;

            if (count($profileNames) !== 1 || $profile === null || $profile['user'] !== 'root' || ! str_starts_with($profile['os_variant'], 'ubuntu')) {
                $this->error('GPU passthrough needs exactly one Ubuntu root profile (ubuntu-root).');

                return self::FAILURE;
            }
        }

        ManageDevelopmentQemuVm::run($profileNames, (bool) $this->option('as-localhost'), (bool) $this->option('fresh'), (bool) $this->option('gpu'));

        foreach ($profileNames as $profileName) {
            $profile = $profiles[$profileName];
            $this->info("Started and seeded {$profile['label']} at {$profile['ip']}.");
        }

        return self::SUCCESS;
    }
}
