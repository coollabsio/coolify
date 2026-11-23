<?php

namespace App\Actions\Server;

use App\Models\Server;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use Spatie\Activitylog\Contracts\Activity;

/**
 * Removes packages that nothing needs anymore, and old kernels. The package managers keep the running kernel.
 */
class RemoveUnusedPackages
{
    use AsAction;

    public string $jobQueue = 'high';

    public function handle(Server $server, ?string $packageManager): Activity|array
    {
        $commands = self::commands($packageManager);
        if ($commands === null) {
            return ['error' => 'Coolify cannot remove unused packages on this operating system.'];
        }
        if ($server->serverStatus() === false) {
            return ['error' => 'Server is not reachable or not ready.'];
        }

        $updateId = (string) Str::uuid();
        $user = auth()->user();

        return remote_process($commands, $server, type_uuid: $updateId, callEventOnFinish: 'ServerPackagesUpdateFinished', callEventData: [
            'update_id' => $updateId,
            'server_uuid' => $server->uuid,
            'packages' => [],
            'action' => 'remove_unused',
            'package_manager' => $packageManager,
            'actor_id' => $user?->id,
            'actor_name' => $user?->name,
            'actor_email' => $user?->email,
        ]);
    }

    public static function supports(?string $packageManager): bool
    {
        return self::commands($packageManager) !== null;
    }

    /**
     * apt autoremove also removes old kernels. dnf autoremove does not, so old install-only packages (kernels) are
     * removed separately; dnf fails when there are none. zypper removes old kernels with purge-kernels.
     *
     * @return list<string>|null Null when the package manager is not supported.
     */
    public static function commands(?string $packageManager): ?array
    {
        return match ($packageManager) {
            'apt' => ['env DEBIAN_FRONTEND=noninteractive apt-get -y autoremove'],
            'dnf' => ['dnf autoremove -y', "sh -c 'dnf remove -y --oldinstallonly || true'"],
            'zypper' => ['zypper --non-interactive purge-kernels'],
            default => null,
        };
    }
}
