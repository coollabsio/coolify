<?php

namespace App\Actions\Server;

use App\Models\Server;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;

class CheckUpdates
{
    use AsAction;

    public string $jobQueue = 'high';

    /**
     * Package managers that only support a full system upgrade. A partial upgrade on Arch Linux is not supported.
     *
     * @see https://wiki.archlinux.org/title/System_maintenance#Partial_upgrades_are_unsupported
     */
    public const FULL_UPGRADE_ONLY = ['pacman'];

    /**
     * Latest upgrade of each installed package. dnf5 does not add a newline after --qf, dnf4 does, so empty lines are skipped.
     */
    public const DNF_UPGRADES_COMMAND = 'LANG=C dnf repoquery -q --refresh --upgrades --latest-limit 1 --qf "%{name} %{arch} %{evr} %{repoid}\n"';

    public const RPM_INSTALLED_COMMAND = 'rpm -qa --qf "%{NAME} %{ARCH} %{VERSION}-%{RELEASE}\n"';

    /**
     * Lists pacman updates without `pacman -Sy` on the live database (that would make the next single install a partial upgrade).
     * `checkupdates` needs fakeroot when it runs as root. Without it, the same sync runs into a temporary database.
     * pacman 7 downloads as the `alpm` user, so the temporary directory must be readable (755).
     *
     * @see https://man.archlinux.org/man/checkupdates.8
     */
    public const PACMAN_CHECK_COMMAND = "sh -c 'if command -v checkupdates >/dev/null 2>&1 && command -v fakeroot >/dev/null 2>&1; then checkupdates; rc=\$?; if [ \$rc -eq 2 ]; then exit 0; fi; exit \$rc; fi; db=\$(mktemp -d) || exit 1; chmod 755 \"\$db\"; ln -s /var/lib/pacman/local \"\$db/local\"; if ! LANG=C pacman -Sy --dbpath \"\$db\" --logfile /dev/null >/dev/null; then rm -rf \"\$db\"; exit 1; fi; pacman -Qu --dbpath \"\$db\" 2>/dev/null; rm -rf \"\$db\"; exit 0'";

    public function handle(Server $server)
    {
        $osId = 'unknown';
        $packageManager = null;

        try {
            if ($server->serverStatus() === false) {
                return [
                    'error' => 'Server is not reachable or not ready.',
                ];
            }

            // Try first method - using instant_remote_process
            $output = instant_remote_process(['cat /etc/os-release'], $server);

            // Parse os-release into an associative array
            $osInfo = [];
            foreach (explode("\n", $output) as $line) {
                if (empty($line)) {
                    continue;
                }
                if (strpos($line, '=') === false) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $osInfo[$key] = trim($value, '"');
            }

            // Get the main OS identifier
            $osId = $osInfo['ID'] ?? '';
            // $osIdLike = $osInfo['ID_LIKE'] ?? '';
            // $versionId = $osInfo['VERSION_ID'] ?? '';

            // Normalize OS types based on install.sh logic
            switch ($osId) {
                case 'manjaro':
                case 'manjaro-arm':
                case 'endeavouros':
                    $osType = 'arch';
                    break;
                case 'pop':
                case 'linuxmint':
                case 'zorin':
                    $osType = 'ubuntu';
                    break;
                case 'fedora-asahi-remix':
                    $osType = 'fedora';
                    break;
                default:
                    $osType = $osId;
            }

            // Determine package manager based on OS type
            $packageManager = match ($osType) {
                'arch' => 'pacman',
                'alpine' => 'apk',
                'ubuntu', 'debian', 'raspbian' => 'apt',
                'centos', 'fedora', 'rhel', 'ol', 'rocky', 'almalinux', 'amzn' => 'dnf',
                'sles', 'opensuse-leap', 'opensuse-tumbleweed' => 'zypper',
                default => null
            };

            switch ($packageManager) {
                case 'zypper':
                    $output = instant_remote_process(['LANG=C zypper -tx list-updates'], $server);
                    $out = $this->parseZypperOutput($output);
                    $out['osId'] = $osId;
                    $out['package_manager'] = $packageManager;

                    return $out;
                case 'dnf':
                    $output = instant_remote_process([self::DNF_UPGRADES_COMMAND], $server);
                    $installed = instant_remote_process([self::RPM_INSTALLED_COMMAND], $server);
                    $out = $this->parseDnfOutput($output, $installed);
                    $out['osId'] = $osId;
                    $out['package_manager'] = $packageManager;

                    return $out;
                case 'apt':
                    instant_remote_process(['apt-get update -qq'], $server);
                    $output = instant_remote_process(['LANG=C apt list --upgradable 2>/dev/null'], $server);

                    $out = $this->parseAptOutput($output);
                    $out['osId'] = $osId;
                    $out['package_manager'] = $packageManager;

                    return $out;
                case 'pacman':
                    $output = instant_remote_process([self::PACMAN_CHECK_COMMAND], $server);
                    $out = $this->parsePacmanOutput($output);
                    $out['osId'] = $osId;
                    $out['package_manager'] = $packageManager;

                    return $out;
                case 'apk':
                    instant_remote_process(['apk update -q'], $server);
                    $output = instant_remote_process(['LANG=C apk list --upgradable 2>/dev/null'], $server);

                    $out = $this->parseApkOutput($output);
                    $out['osId'] = $osId;
                    $out['package_manager'] = $packageManager;

                    return $out;
                default:
                    return [
                        'osId' => $osId,
                        'error' => 'Unsupported package manager',
                        'package_manager' => $packageManager,
                    ];
            }
        } catch (\Throwable $e) {

            return [
                'osId' => $osId,
                'package_manager' => $packageManager,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ];
        }
    }

    public function parseZypperOutput(string $output): array
    {
        $updates = [];

        try {
            $xml = simplexml_load_string($output);
            if ($xml === false) {
                return [
                    'total_updates' => 0,
                    'updates' => [],
                    'error' => 'Failed to parse XML output',
                ];
            }

            // Navigate to the update-list node
            $updateList = $xml->xpath('//update-list/update');

            foreach ($updateList as $update) {
                $updates[] = [
                    'package' => (string) $update['name'],
                    'new_version' => (string) $update['edition'],
                    'current_version' => (string) $update['edition-old'],
                    'architecture' => (string) $update['arch'],
                    'repository' => (string) $update->source['alias'],
                    'summary' => (string) $update->summary,
                    'description' => (string) $update->description,
                ];
            }

            return [
                'total_updates' => count($updates),
                'updates' => $updates,
            ];
        } catch (\Throwable $e) {
            return [
                'total_updates' => 0,
                'updates' => [],
                'error' => 'Error parsing zypper output: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @param  string  $output  Output of DNF_UPGRADES_COMMAND: "name arch evr repoid" per line.
     * @param  string  $installed  Output of RPM_INSTALLED_COMMAND: "name arch version-release" per line.
     */
    public function parseDnfOutput(string $output, string $installed = ''): array
    {
        $installedVersions = [];
        foreach (explode("\n", $installed) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) === 3) {
                $installedVersions["{$parts[0]}.{$parts[1]}"] = $parts[2];
            }
        }

        $updates = [];
        foreach (explode("\n", $output) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) !== 4) {
                continue;
            }
            [$package, $architecture, $newVersion, $repository] = $parts;

            $updates["{$package}.{$architecture}"] = [
                'package' => $package,
                'new_version' => preg_replace('/^0:/', '', $newVersion),
                'repository' => $repository,
                'architecture' => $architecture,
                'current_version' => $installedVersions["{$package}.{$architecture}"] ?? 'unknown',
            ];
        }
        $updates = array_values($updates);

        return [
            'total_updates' => count($updates),
            'updates' => $updates,
        ];
    }

    public function parseAptOutput(string $output): array
    {
        $updates = [];
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            // Skip the "Listing... Done" line and empty lines
            if (empty($line) || str_contains($line, 'Listing...')) {
                continue;
            }

            // Example line: package/stable 2.0-1 amd64 [upgradable from: 1.0-1]
            if (preg_match('/^(.+?)\/(\S+)\s+(\S+)\s+(\S+)\s+\[upgradable from: (.+?)\]/', $line, $matches)) {
                $updates[] = [
                    'package' => $matches[1],
                    'repository' => $matches[2],
                    'new_version' => $matches[3],
                    'architecture' => $matches[4],
                    'current_version' => $matches[5],
                ];
            }
        }

        return [
            'total_updates' => count($updates),
            'updates' => $updates,
        ];
    }

    public function parsePacmanOutput(string $output): array
    {
        $updates = [];
        $unparsedLines = [];
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            if (empty($line)) {
                continue;
            }
            // Format: package current_version -> new_version
            if (preg_match('/^(\S+)\s+(\S+)\s+->\s+(\S+)$/', $line, $matches)) {
                $updates[] = [
                    'package' => $matches[1],
                    'current_version' => $matches[2],
                    'new_version' => $matches[3],
                    'architecture' => 'unknown',
                    'repository' => 'unknown',
                ];
            } else {
                // Log unmatched lines for debugging purposes
                $unparsedLines[] = $line;
            }
        }

        $result = [
            'total_updates' => count($updates),
            'updates' => $updates,
        ];

        // Include unparsed lines in the result for debugging if any exist
        if (! empty($unparsedLines)) {
            $result['unparsed_lines'] = $unparsedLines;
            Log::debug('Pacman output contained unparsed lines', [
                'unparsed_lines' => $unparsedLines,
            ]);
        }

        return $result;
    }

    public function parseApkOutput(string $output): array
    {
        $updates = [];
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            // Skip empty lines
            if (empty($line)) {
                continue;
            }

            // Example line: docker-cli-compose-2.31.0-r5 x86_64 {docker-cli-compose} (Apache-2.0) [upgradable from: docker-cli-compose-2.31.0-r4]
            if (preg_match('/^(.+)-([0-9]\S*) (\S+) \{\S+\} \([^)]+\) \[upgradable from: .+?-([0-9][^\]]+)\]$/', $line, $matches)) {
                $updates[] = [
                    'package' => $matches[1],
                    'new_version' => $matches[2],
                    'architecture' => $matches[3],
                    'current_version' => $matches[4],
                ];
            }
        }

        return [
            'total_updates' => count($updates),
            'updates' => $updates,
        ];
    }
}
