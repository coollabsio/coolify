<?php

namespace App\Actions\Server;

use App\Models\Server;
use Illuminate\Support\Str;
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

            if (! $all && in_array($packageManager, CheckUpdates::FULL_UPGRADE_ONLY, true)) {
                return [
                    'error' => 'This operating system supports only a full system upgrade. Use "Update all packages".',
                ];
            }

            $commands = self::commands($packageManager, $all ? null : $sanitizedPackage);
            if ($commands === null) {
                return [
                    'error' => 'OS not supported',
                ];
            }

            $updateId = (string) Str::uuid();
            $packages = $all ? [] : [$package];
            $user = auth()->user();

            return remote_process($commands, $server, type_uuid: $updateId, callEventOnFinish: 'ServerPackagesUpdateFinished', callEventData: [
                'update_id' => $updateId,
                'server_uuid' => $server->uuid,
                'packages' => $packages,
                'package_manager' => $packageManager,
                'actor_id' => $user?->id,
                'actor_name' => $user?->name,
                'actor_email' => $user?->email,
            ]);
        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Update commands that never wait for input. apt keeps changed configuration files (confold) and uses the
     * package default for unchanged ones (confdef). A null package updates all packages.
     *
     * @return array<int, string>|null
     */
    public static function commands(?string $packageManager, ?string $escapedPackage = null): ?array
    {
        $aptOptions = '-y -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold';

        return match ($packageManager) {
            'apt' => [
                'env DEBIAN_FRONTEND=noninteractive apt-get update -qq',
                $escapedPackage === null
                    ? "env DEBIAN_FRONTEND=noninteractive apt-get {$aptOptions} upgrade"
                    : "env DEBIAN_FRONTEND=noninteractive apt-get {$aptOptions} install --only-upgrade {$escapedPackage}",
            ],
            'dnf' => [$escapedPackage === null ? 'dnf upgrade -y' : "dnf upgrade -y {$escapedPackage}"],
            'zypper' => [$escapedPackage === null ? 'zypper --non-interactive update' : "zypper --non-interactive update {$escapedPackage}"],
            'pacman' => $escapedPackage === null ? ['pacman -Syu --noconfirm'] : null,
            'apk' => ['apk update', $escapedPackage === null ? 'apk upgrade' : "apk upgrade {$escapedPackage}"],
            default => null,
        };
    }
}
