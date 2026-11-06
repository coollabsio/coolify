<?php

namespace App\Actions\ImageScan;

use App\Enums\ImageScanErrorCode;
use App\Jobs\ImageRescanJob;
use App\Models\Server;
use App\Models\ServerHostScan;
use App\Services\ImageScan\Finding;
use App\Services\ImageScan\GrypeReportParser;
use App\Services\ImageScan\RelevanceRules;
use App\Services\ImageScan\ScanFailed;
use App\Services\ImageScan\ScannerCommands;
use App\Services\ImageScan\SecurityRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

/**
 * Scans the OS packages of a server with the image scanner tools and saves the result on its `ServerHostScan` row.
 * Runs under the image scan lock of the server, so it never runs at the same time as an image scan.
 */
class ScanHostPackages
{
    use AsAction;

    private const TIMEOUT_DEFAULT = 120;

    private const TIMEOUT_SBOM = 720;

    private const TIMEOUT_GRYPE = 360;

    /** A host has more findings than an image; all of them are counted. */
    private const MAX_PARSED_FINDINGS = 20000;

    /**
     * @return bool False when another scan holds the server lock: the caller tries again later.
     */
    public function handle(Server $server): bool
    {
        if (! ImageRescanJob::canRunOn($server)) {
            return true;
        }

        $lock = ScanImage::serverLock($server);
        if (! ScanImage::acquireServerLock($lock, $server, 0)) {
            return false;
        }

        $commands = new ScannerCommands($server, Str::lower(Str::random(24)));
        try {
            $databaseBuiltAt = ScanImage::databaseBuiltAt($commands);

            $sbom = ScanImage::runStep($server, $commands->sbomFromHost(), 'sbom', self::TIMEOUT_SBOM);
            if (($sbom['exit_codes']['copy'] ?? 1) !== 0) {
                throw new ScanFailed(ImageScanErrorCode::NoPackageDatabase);
            }
            ScanImage::assertSucceeded($sbom, 'sbom');

            $grype = ScanImage::runStep($server, $commands->grype(ScannerCommands::HOST_SBOM), 'grype', self::TIMEOUT_GRYPE);
            ScanImage::assertSucceeded($grype, 'grype');

            $this->complete($server, $grype, $databaseBuiltAt);
        } catch (ScanFailed $e) {
            $this->fail($server, $e->errorCode);
        } catch (Throwable $e) {
            report($e);
            $this->fail($server, ImageScanErrorCode::Unknown);
        } finally {
            instant_remote_process($commands->cleanup(), $server, throwError: false, timeout: self::TIMEOUT_DEFAULT);
            $lock->release();
        }

        return true;
    }

    /**
     * Stores the relevant findings (spec 15.2), problems with an exploit signal first, then the ones an update fixes.
     *
     * @param  array{code: int, size: ?int, payload: string, exit_codes: array<string, int>, oom: list<string>}  $grype
     */
    private function complete(Server $server, array $grype, CarbonImmutable $databaseBuiltAt): void
    {
        [$payload, $cut] = ScanImage::readPayload($grype);
        $report = (new GrypeReportParser)->parse($payload, self::MAX_PARSED_FINDINGS);
        $rules = RelevanceRules::fromIgnores(collect());

        $relevant = array_values(array_filter($report['findings'], fn (Finding $finding): bool => SecurityRules::isRelevant($finding, $rules)));
        usort($relevant, fn (Finding $a, Finding $b): int => $this->rank($a) <=> $this->rank($b));

        $summary = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'unknown' => 0, 'fixable' => 0, 'exploited' => 0, 'likely' => 0, 'total' => count($report['findings'])];
        foreach ($relevant as $finding) {
            $summary[$finding->severity]++;
            $summary['fixable'] += $finding->fixedIn !== null ? 1 : 0;
            $signal = SecurityRules::exploitSignal($finding);
            $summary['exploited'] += $signal === SecurityRules::EXPLOITED ? 1 : 0;
            $summary['likely'] += $signal === SecurityRules::LIKELY ? 1 : 0;
        }

        ServerHostScan::query()->updateOrCreate(['server_id' => $server->id], [
            'error_code' => null,
            'eol' => $report['eol'],
            'summary' => $summary,
            'findings' => array_map(fn (Finding $finding): array => $finding->toArray(), array_slice($relevant, 0, ServerHostScan::MAX_STORED_FINDINGS)),
            'findings_truncated' => $cut || $report['truncated'] || count($relevant) > ServerHostScan::MAX_STORED_FINDINGS,
            'grype_db_built_at' => $databaseBuiltAt,
            'scanned_at' => now(),
        ]);
    }

    /**
     * @return array{0: int, 1: int, 2: float}
     */
    private function rank(Finding $finding): array
    {
        $signal = match (SecurityRules::exploitSignal($finding)) {
            SecurityRules::EXPLOITED => 0,
            SecurityRules::LIKELY => 1,
            default => 2,
        };

        return [$signal, $finding->fixedIn !== null ? 0 : 1, -($finding->risk ?? 0.0)];
    }

    /**
     * Keeps the findings of the last completed scan.
     */
    private function fail(Server $server, ImageScanErrorCode $code): void
    {
        ServerHostScan::query()->updateOrCreate(['server_id' => $server->id], ['error_code' => $code]);
    }
}
