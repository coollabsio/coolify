<?php

namespace App\Services\ImageScan;

use App\Enums\ImageScanErrorCode;
use App\Enums\ImageScanGateMode;
use App\Enums\ImageScanOnError;
use App\Enums\ImageScanStatus;
use App\Models\ImageScan;

/**
 * "Block deploys" (spec 15.8): decides from the stored findings of the new image whether a deploy starts. No remote
 * calls.
 *
 * - On: blocks when the new image has a relevant finding with an exploit signal that the running image of the app
 *   does not have (by ID and aliases). Such findings that the running image has too are listed as a warning, so an
 *   EPSS change of a finding that already runs never blocks. Without a running image every finding is new.
 * - Strict: blocks on any relevant finding with an exploit signal, any relevant critical or high finding with a
 *   package fix, and an end-of-life OS.
 * - Off never blocks. A scan that cannot run follows "If the scan cannot run".
 */
final class SecurityGate
{
    private const LOG_LIMIT = 5;

    private const SERIOUS = ['critical', 'high'];

    /**
     * @param  array<string, int|string>  $ctx  Context of the error message (server).
     *                                          `headline` counts the problems (email, deploy log); `headline_plain` does not (chat and webhook channels).
     * @return array{blocked: bool, headline: string, headline_plain: string, new: list<array<string, mixed>>, carried: list<array<string, mixed>>, error: ?string, scan_ran: bool, mode: string, eol: ?array{name: string, version: string}}
     */
    public static function evaluate(?ImageScan $new, ?ImageScan $running, string $mode, IgnoreMatcher $ignores, ?ImageScanErrorCode $error = null, array $ctx = [], ImageScanOnError $onScanError = ImageScanOnError::Deploy): array
    {
        $mode = ImageScanGateMode::tryFrom($mode) ?? ImageScanGateMode::Off;
        $completed = $new !== null && $new->status === ImageScanStatus::Completed;

        if ($mode === ImageScanGateMode::Off) {
            return self::result(scanRan: $completed && $error === null, mode: $mode);
        }

        if ($error === null && ! $completed) {
            $error = ImageScanErrorCode::Unknown;
        }

        if ($error !== null) {
            $blocked = $onScanError === ImageScanOnError::Block;
            $headline = ($blocked ? 'Deploy blocked: ' : 'Scan skipped: ').$error->message($ctx);

            return self::result(
                blocked: $blocked,
                headline: $headline,
                headlinePlain: $headline,
                error: $error->value,
                mode: $mode,
            );
        }

        $runningIds = $running === null ? [] : self::idSet(ResourceScans::findings($running));
        $blocking = [];
        $carried = [];
        $seen = [];
        foreach (ResourceScans::findings($new) as $finding) {
            $key = mb_strtolower($finding->id);
            if (isset($seen[$key]) || ! SecurityRules::isRelevant($finding, $ignores)) {
                continue;
            }
            $seen[$key] = true;

            $signal = SecurityRules::exploitSignal($finding);
            if ($mode === ImageScanGateMode::Strict) {
                if ($signal !== null || self::hasSeriousPackageFix($finding)) {
                    $blocking[] = self::row($finding, $signal);
                }

                continue;
            }

            if ($signal === null) {
                continue;
            }
            if (self::inSet($finding, $runningIds)) {
                $carried[] = self::row($finding, $signal);
            } else {
                $blocking[] = self::row($finding, $signal);
            }
        }

        $eol = null;
        $eolDistro = data_get($new->summary, 'eol_distro');
        if ($mode === ImageScanGateMode::Strict && is_array($eolDistro)) {
            $eol = [
                'name' => ScannerText::clean($eolDistro['name'] ?? '', 64),
                'version' => ScannerText::clean($eolDistro['version'] ?? '', 32),
            ];
        }

        $blocking = self::sorted($blocking);
        $blocked = $blocking !== [] || $eol !== null;

        return self::result(
            blocked: $blocked,
            headline: $blocked ? self::headline($blocking, $eol) : '',
            headlinePlain: $blocked ? self::headline($blocking, $eol, counted: false) : '',
            new: $blocking,
            carried: self::sorted($carried),
            scanRan: true,
            mode: $mode,
            eol: $eol,
        );
    }

    /**
     * The deploy log lines of a result: the headline and the blocking problems, or "passed", then the problems with
     * an exploit signal that the running image has too.
     *
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    public static function logLines(array $result): array
    {
        $lines = [];
        $error = $result['error'] ?? null;
        $headline = (string) ($result['headline'] ?? '');

        if ($error !== null) {
            return $headline === '' ? [] : [$headline];
        }

        if (! ($result['scan_ran'] ?? false)) {
            return [];
        }

        if ($result['blocked'] ?? false) {
            $lines[] = $headline;
            $lines = [...$lines, ...self::rowLines((array) ($result['new'] ?? []), null)];
            $eol = $result['eol'] ?? null;
            if (is_array($eol)) {
                $lines[] = '  '.trim('This image uses '.ucfirst((string) $eol['name']).' '.$eol['version']).', which no longer gets security fixes.';
            }
            $lines[] = 'See the Security page for all problems.';
        } else {
            $lines[] = 'Image scan: passed.';
        }

        $carried = (array) ($result['carried'] ?? []);
        if ($carried !== []) {
            $use = collect($carried)->contains(fn (array $row): bool => $row['signal'] === SecurityRules::EXPLOITED) ? 'are using' : 'are likely to use';
            $lines[] = 'Already in the running image: '.self::problems(count($carried))." that attackers {$use}.";
            $lines = [...$lines, ...self::rowLines($carried, 'already running')];
        }

        return $lines;
    }

    /**
     * The result as it is stored on the deployment (`scan_gate_result`): the surfaces read `blocked`, `scan_ran`,
     * `error`, `count`, `headline`, and `top` (the blocking problems).
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public static function stored(array $result): array
    {
        $new = (array) ($result['new'] ?? []);

        return [
            'blocked' => (bool) ($result['blocked'] ?? false),
            'scan_ran' => (bool) ($result['scan_ran'] ?? false),
            'error' => $result['error'] ?? null,
            'mode' => (string) ($result['mode'] ?? ImageScanGateMode::Off->value),
            'count' => count($new) + (is_array($result['eol'] ?? null) ? 1 : 0),
            'headline' => (string) ($result['headline'] ?? ''),
            'headline_plain' => (string) ($result['headline_plain'] ?? ''),
            'top' => array_slice($new, 0, self::LOG_LIMIT),
            'carried' => array_slice((array) ($result['carried'] ?? []), 0, self::LOG_LIMIT),
            'carried_count' => count((array) ($result['carried'] ?? [])),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $blocking
     * @param  array{name: string, version: string}|null  $eol
     */
    private static function headline(array $blocking, ?array $eol, bool $counted = true): string
    {
        $signal = array_values(array_filter($blocking, fn (array $row): bool => $row['signal'] !== null));
        if ($signal !== []) {
            $use = collect($signal)->contains(fn (array $row): bool => $row['signal'] === SecurityRules::EXPLOITED) ? 'are using' : 'are likely to use';

            return $counted
                ? 'Deploy blocked: this image has '.self::problems(count($signal))." that attackers {$use}."
                : "Deploy blocked: this image has security problems that attackers {$use}.";
        }

        if (! $counted) {
            return 'Deploy blocked: this image needs a security update.';
        }

        $count = count($blocking) + ($eol !== null ? 1 : 0);

        return 'Deploy blocked: this image has '.self::problems($count).' that '.($count === 1 ? 'needs' : 'need').' a security update.';
    }

    private static function problems(int $count): string
    {
        return $count > 1 ? "{$count} security problems" : 'a security problem';
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private static function rowLines(array $rows, ?string $suffix): array
    {
        $lines = [];
        foreach (array_slice($rows, 0, self::LOG_LIMIT) as $row) {
            $score = is_numeric($row['score'] ?? null) ? number_format((float) $row['score'], 1).' ' : '';
            $note = $suffix ?? match ($row['signal'] ?? null) {
                SecurityRules::EXPLOITED => 'used by attackers',
                SecurityRules::LIKELY => 'likely to be exploited',
                default => filled($row['fixed_in'] ?? null) ? 'fixed in '.$row['fixed_in'] : '',
            };
            $lines[] = rtrim("  {$row['display_id']}  {$score}".ucfirst((string) $row['severity'])."  {$row['package']} {$row['version']}  {$note}");
        }
        if (count($rows) > self::LOG_LIMIT) {
            $lines[] = '  and '.(count($rows) - self::LOG_LIMIT).' more';
        }

        return $lines;
    }

    private static function hasSeriousPackageFix(Finding $finding): bool
    {
        return in_array(strtolower($finding->severity), self::SERIOUS, true)
            && $finding->fixState === 'fixed'
            && filled($finding->fixedIn);
    }

    /**
     * @param  list<Finding>  $findings
     * @return array<string, true>
     */
    private static function idSet(array $findings): array
    {
        $ids = [];
        foreach ($findings as $finding) {
            foreach ([$finding->id, ...$finding->aliases] as $id) {
                if ($id !== '') {
                    $ids[mb_strtolower($id)] = true;
                }
            }
        }

        return $ids;
    }

    /**
     * @param  array<string, true>  $ids
     */
    private static function inSet(Finding $finding, array $ids): bool
    {
        foreach ([$finding->id, ...$finding->aliases] as $id) {
            if ($id !== '' && isset($ids[mb_strtolower($id)])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{id: string, display_id: string, severity: string, score: ?float, package: string, version: string, fixed_in: ?string, kev: bool, signal: ?string}
     */
    private static function row(Finding $finding, ?string $signal): array
    {
        return [
            'id' => ScannerText::clean($finding->id, 128),
            'display_id' => ScannerText::clean($finding->displayId, 128),
            'severity' => strtolower(ScannerText::clean($finding->severity, 16)),
            'score' => $finding->cvssScore,
            'package' => ScannerText::clean($finding->package, 200),
            'version' => ScannerText::clean($finding->version, 100),
            'fixed_in' => $finding->fixedIn === null ? null : ScannerText::clean($finding->fixedIn, 100),
            'kev' => $finding->kev,
            'signal' => $signal,
        ];
    }

    /**
     * Exploited first, then likely, then the score.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function sorted(array $rows): array
    {
        $rank = fn (?string $signal): int => match ($signal) {
            SecurityRules::EXPLOITED => 2,
            SecurityRules::LIKELY => 1,
            default => 0,
        };
        $severity = ['critical' => 2, 'high' => 1];
        usort($rows, fn (array $a, array $b): int => [$rank($b['signal']), $b['score'] ?? -1.0, $severity[$b['severity']] ?? 0]
            <=> [$rank($a['signal']), $a['score'] ?? -1.0, $severity[$a['severity']] ?? 0]);

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $new
     * @param  list<array<string, mixed>>  $carried
     * @param  array{name: string, version: string}|null  $eol
     * @return array{blocked: bool, headline: string, headline_plain: string, new: list<array<string, mixed>>, carried: list<array<string, mixed>>, error: ?string, scan_ran: bool, mode: string, eol: ?array{name: string, version: string}}
     */
    private static function result(bool $blocked = false, string $headline = '', string $headlinePlain = '', array $new = [], array $carried = [], ?string $error = null, bool $scanRan = false, ImageScanGateMode $mode = ImageScanGateMode::Off, ?array $eol = null): array
    {
        return [
            'blocked' => $blocked,
            'headline' => $headline,
            'headline_plain' => $headlinePlain,
            'new' => $new,
            'carried' => $carried,
            'error' => $error,
            'scan_ran' => $scanRan,
            'mode' => $mode->value,
            'eol' => $eol,
        ];
    }
}
