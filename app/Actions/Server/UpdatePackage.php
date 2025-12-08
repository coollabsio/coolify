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

            // Validate that package name is provided when not updating all packages
            if (! $all && ($package === null || $package === '')) {
                return [
                    'error' => "Package name required when 'all' is false.",
                ];
            }

            // Sanitize package name to prevent command injection
            // Only allow alphanumeric characters, hyphens, underscores, periods, plus signs, and colons
            // These are valid characters in package names across most package managers
            $sanitizedPackage = '';
            if ($package !== null && ! $all) {
                if (! preg_match('/^[a-zA-Z0-9._+:-]+$/', $package)) {
                    return [
                        'error' => 'Invalid package name. Package names can only contain alphanumeric characters, hyphens, underscores, periods, plus signs, and colons.',
                    ];
                }
                $sanitizedPackage = escapeshellarg($package);
            }

            switch ($packageManager) {
                case 'zypper':
                    $commandAll = 'zypper update -y';
                    $commandInstall = 'zypper install -y '.$sanitizedPackage;
                    break;
                case 'dnf':
                    $commandAll = 'dnf update -y';
                    $commandInstall = 'dnf update -y '.$sanitizedPackage;
                    break;
                case 'apt':
                    $commandAll = 'apt update && apt upgrade -y';
                    $commandInstall = 'apt install -y '.$sanitizedPackage;
                    break;
                case 'pacman':
                    $commandAll = 'pacman -Syu --noconfirm';
                    $commandInstall = 'pacman -S --noconfirm '.$sanitizedPackage;
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
