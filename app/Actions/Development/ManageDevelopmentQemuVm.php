<?php

namespace App\Actions\Development;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Process;
use Lorisleiva\Actions\Concerns\AsAction;

class ManageDevelopmentQemuVm
{
    use AsAction;

    /** @param string|array<int, string> $profileNames */
    public function handle(string|array $profileNames): void
    {
        $profileNames = is_array($profileNames) ? array_values(array_unique($profileNames)) : [$profileNames];

        foreach ($profileNames as $index => $profileName) {
            StartDevelopmentQemuVm::run($profileName, $index === 0);

            try {
                SeedDevelopmentQemuServer::run($profileName, $index === 0);
            } catch (QueryException $exception) {
                $keepOthers = $index === 0 ? '' : ' --keep-others';
                $result = Process::run('docker exec coolify php artisan dev:qemu:seed '.escapeshellarg($profileName).$keepOthers);

                if ($result->failed()) {
                    throw $exception;
                }
            }
        }
    }
}
