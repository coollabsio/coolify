<?php

namespace App\Actions\Server;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;
use Spatie\Activitylog\Contracts\Activity;

class UpdatePackage
{
    use AsAction;

    public string $jobQueue = 'high';

    public function handle(Server $server, string $osId, ?string $package = null, ?string $packageManager = null, bool $all = false): Activity|array
    {
        try {
            if ($server->serverStatus() === false) {
                return [
                    'error' => 'Server is not reachable or not ready.',
                ];
            }
            switch ($packageManager) {
                case 'zypper':
                    $commandAll = 'zypper update -y';
                    $commandInstall = 'zypper install -y '.$package;
                    break;
                case 'dnf':
                    $commandAll = 'dnf update -y';
                    $commandInstall = 'dnf update -y '.$package;
                    break;
                case 'apt':
                    $commandAll = 'apt update && apt upgrade -y';
                    $commandInstall = 'apt install -y '.$package;
                    break;
                default:
                    return [
                        'error' => 'OS not supported',
                    ];
            }
            if ($all) {
                return remote_process([$commandAll], $server);
            }

            return remote_process([$commandInstall], $server);
        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }
    }
}
