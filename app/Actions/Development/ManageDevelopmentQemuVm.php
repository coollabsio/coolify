<?php

namespace App\Actions\Development;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Process;
use Lorisleiva\Actions\Concerns\AsAction;

class ManageDevelopmentQemuVm
{
    use AsAction;

    /**
     * Start (or create) the selected VMs and seed their servers. Existing VMs are reused unless $fresh is set.
     * With $gpu, the host GPU is passed through and the container seeds the server, because it installs the
     * driver over Coolify's SSH connection.
     *
     * @param  string|array<int, string>  $profileNames
     */
    public function handle(string|array $profileNames, bool $asLocalhost = false, bool $fresh = false, bool $gpu = false): void
    {
        $profileNames = is_array($profileNames) ? array_values(array_unique($profileNames)) : [$profileNames];

        foreach ($profileNames as $index => $profileName) {
            StartDevelopmentQemuVm::run($profileName, $fresh, $gpu);

            if ($gpu) {
                $this->seedInContainer($profileName, $index > 0, $asLocalhost, true)->throw();

                continue;
            }

            try {
                SeedDevelopmentQemuServer::run($profileName, $index === 0, $asLocalhost);
            } catch (QueryException $exception) {
                if ($this->seedInContainer($profileName, $index > 0, $asLocalhost, false)->failed()) {
                    throw $exception;
                }
            }
        }
    }

    private function seedInContainer(string $profileName, bool $keepOthers, bool $asLocalhost, bool $gpu): ProcessResult
    {
        $container = escapeshellarg(config('development-qemu.coolify_container'));
        $command = "docker exec {$container} php artisan dev:qemu:seed ".escapeshellarg($profileName)
            .($keepOthers ? ' --keep-others' : '')
            .($asLocalhost ? ' --as-localhost' : '')
            .($gpu ? ' --gpu' : '');

        return $gpu
            ? Process::forever()->run($command, fn (string $type, string $output) => fwrite(STDOUT, $output))
            : Process::run($command);
    }
}
