<?php

namespace App\Actions\Server;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Reads the last finished run of the automatic security updates that ConfigureAutomaticSecurityUpdates set up.
 *
 * apt: the last section of the unattended-upgrades log, which starts with "Starting unattended upgrades script"
 * (https://github.com/mvo5/unattended-upgrades/blob/master/unattended-upgrade). dnf: the last run of the
 * dnf(5)-automatic service from systemd, and the packages that rpm installed while it ran.
 *
 * @phpstan-type AutomaticSecurityUpdatesRun array{started_at: string, failed: bool, packages: list<string>, errors: list<string>, reboot_required: ?bool}
 */
class CheckAutomaticSecurityUpdatesRun
{
    use AsAction;

    /**
     * Prints the log from the last "Starting unattended upgrades script" line to the end.
     */
    public const APT_LAST_RUN_COMMAND = <<<'SH'
sh -c 'f=/var/log/unattended-upgrades/unattended-upgrades.log; if [ -f $f ]; then tail -n 2000 $f | tac | sed "/Starting unattended upgrades script/q" | tac; fi; exit 0'
SH;

    /**
     * Prints RUN, STATE, RESULT, FINISHED=yes, and one PKG line for each package that rpm installed between the
     * start and the end of the run (with one minute of tolerance). A failed run also prints the last journal lines.
     */
    public const DNF_LAST_RUN_COMMAND = <<<'SH'
sh -c 't=dnf-automatic.service; if [ -e /usr/lib/systemd/system/dnf5-automatic.service ]; then t=dnf5-automatic.service; fi; s=$(systemctl show -p ExecMainStartTimestamp --value $t); if [ -z "$s" ]; then exit 0; fi; e=$(systemctl show -p ExecMainExitTimestamp --value $t); r=$(systemctl show -p Result --value $t); echo "RUN=$s"; echo "STATE=$(systemctl show -p ActiveState --value $t)"; echo "RESULT=$r"; ss=$(date -d "$s" +%s); ee=$(date +%s); if [ -n "$e" ]; then ee=$(date -d "$e" +%s); if [ "$ee" -ge "$ss" ]; then echo FINISHED=yes; fi; fi; rpm -qa --qf "%{INSTALLTIME} %{NAME}\n" 2>/dev/null | awk -v s="$ss" -v e="$ee" "\$1 >= s && \$1 <= e + 60 { print \"PKG=\" \$2 }"; if [ "$r" != success ]; then journalctl -u $t -n 5 --no-pager -o cat 2>/dev/null | sed "s/^/ERROR=/"; fi; exit 0'
SH;

    private const APT_RUN_START = 'Starting unattended upgrades script';

    /**
     * Log lines that only appear when unattended-upgrades finished its work.
     */
    private const APT_FINISH_MARKERS = [
        'All upgrades installed',
        'No packages found that can be upgraded unattended',
        'Installing the upgrades failed',
    ];

    /**
     * @return AutomaticSecurityUpdatesRun|null The last finished run, or null when there is none yet.
     */
    public function handle(Server $server, string $packageManager): ?array
    {
        $command = match ($packageManager) {
            'apt' => self::APT_LAST_RUN_COMMAND,
            'dnf' => self::DNF_LAST_RUN_COMMAND,
        };
        $output = (string) instant_remote_process([$command, CheckUpdates::REBOOT_CHECK_COMMAND], $server, false);

        $run = $packageManager === 'apt' ? self::parseAptLog($output) : self::parseDnfOutput($output);
        if ($run === null) {
            return null;
        }

        return [...$run, 'reboot_required' => (new CheckUpdates)->parseRebootStatus($output)];
    }

    /**
     * @return array{started_at: string, failed: bool, packages: list<string>, errors: list<string>}|null
     */
    public static function parseAptLog(string $output): ?array
    {
        $startedAt = null;
        $packages = [];
        $errors = [];
        $finished = false;

        foreach (preg_split('/\R/', $output) as $line) {
            if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\S* (\w+) (.*)$/', $line, $matches) !== 1) {
                continue;
            }
            [, $time, $level, $message] = $matches;

            if (str_contains($message, self::APT_RUN_START)) {
                $startedAt = $time;
                $packages = [];
                $errors = [];
                $finished = false;

                continue;
            }
            if ($startedAt === null) {
                continue;
            }
            if (in_array($level, ['ERROR', 'CRITICAL'], true)) {
                $errors[] = $message;
                $finished = true;
            }
            if (preg_match('/^Packages that will be upgraded: (.*)$/', $message, $packageMatches) === 1) {
                $packages = [...$packages, ...preg_split('/\s+/', trim($packageMatches[1]), -1, PREG_SPLIT_NO_EMPTY)];
            }
            if (array_any(self::APT_FINISH_MARKERS, fn (string $marker) => str_contains($message, $marker))) {
                $finished = true;
            }
        }

        if ($startedAt === null || ! $finished) {
            return null;
        }

        return [
            'started_at' => $startedAt,
            'failed' => $errors !== [],
            'packages' => array_values(array_unique($packages)),
            'errors' => $errors,
        ];
    }

    /**
     * @return array{started_at: string, failed: bool, packages: list<string>, errors: list<string>}|null
     */
    public static function parseDnfOutput(string $output): ?array
    {
        $values = ['RUN' => null, 'STATE' => null, 'RESULT' => null, 'FINISHED' => null];
        $packages = [];
        $errors = [];

        foreach (preg_split('/\R/', $output) as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            match ($key) {
                'PKG' => $packages[] = trim($value),
                'ERROR' => $errors[] = trim($value),
                'RUN', 'STATE', 'RESULT', 'FINISHED' => $values[$key] = trim($value),
                default => null,
            };
        }

        $finished = $values['FINISHED'] === 'yes' && in_array($values['STATE'], ['inactive', 'failed'], true);
        if (blank($values['RUN']) || ! $finished) {
            return null;
        }

        $failed = $values['RESULT'] !== 'success';

        return [
            'started_at' => $values['RUN'],
            'failed' => $failed,
            'packages' => array_values(array_unique(array_filter($packages))),
            'errors' => $failed ? array_values(array_filter($errors)) : [],
        ];
    }
}
