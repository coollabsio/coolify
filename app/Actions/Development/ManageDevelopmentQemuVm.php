<?php

namespace App\Actions\Development;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Process;
use Lorisleiva\Actions\Concerns\AsAction;

class ManageDevelopmentQemuVm
{
    use AsAction;

    /**
     * Start (or create) the selected VMs and seed their servers. Existing VMs are reused unless $fresh is set.
     *
     * @param  string|array<int, string>  $profileNames
     */
    public function handle(string|array $profileNames, bool $asLocalhost = false, bool $fresh = false): void
    {
        $profileNames = is_array($profileNames) ? array_values(array_unique($profileNames)) : [$profileNames];

        foreach ($profileNames as $index => $profileName) {
            StartDevelopmentQemuVm::run($profileName, $fresh);

            try {
                SeedDevelopmentQemuServer::run($profileName, $index === 0, $asLocalhost);
            } catch (QueryException $exception) {
                $keepOthers = $index === 0 ? '' : ' --keep-others';
                $localhostOption = $asLocalhost ? ' --as-localhost' : '';
                $container = escapeshellarg(config('development-qemu.coolify_container'));
                $result = Process::run("docker exec {$container} php artisan dev:qemu:seed ".escapeshellarg($profileName).$keepOthers.$localhostOption);

                if ($result->failed()) {
                    throw $exception;
                }
            }
        }
    }
}
