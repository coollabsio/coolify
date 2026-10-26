<?php

namespace App\Services\ImageScan;

use App\Enums\BuildPackTypes;
use App\Enums\ImageScanErrorCode;
use App\Enums\ImageScanFixState;
use App\Enums\ImageScanOnError;
use App\Enums\ImageScanStatus;
use App\Models\Application;
use App\Models\ImageScan;

/**
 * Decides whether a finished scan blocks a deploy.
 */
final class ImageScanGate
{
    private const HEADLINE_SEVERITIES = ['critical', 'high', 'medium'];

    private const LOG_SEVERITIES = ['critical', 'high', 'medium', 'low', 'unknown'];

    private const TOP_LIMIT = 5;

    /**
     * @param  array<string, mixed>  $errorContext
     */
    public function evaluate(?ImageScan $scan, ResolvedImageScanPolicy $policy, IgnoreMatcher $ignores, ?ImageScanErrorCode $error = null, array $errorContext = []): GateResult
    {
        $completed = $scan !== null && $scan->status === ImageScanStatus::Completed;

        if (! $policy->gateEnabled) {
            return new GateResult(false, $completed && $error === null, null, 0, '', []);
        }

        if ($error === null && ! $completed) {
            $error = ImageScanErrorCode::Unknown;
        }

        if ($error !== null) {
            $message = $error->message($errorContext);
            $blocked = $policy->onScanError === ImageScanOnError::Block;

            return new GateResult(
                blocked: $blocked,
                scanRan: false,
                error: $error,
                count: 0,
                headline: ($blocked ? 'Deploy blocked: ' : 'Scan skipped: ').$message,
                top: [],
            );
        }

        $blocking = $this->blockingFindings($scan, $policy, $ignores);

        if ($blocking === []) {
            return new GateResult(false, true, null, 0, '', []);
        }

        return new GateResult(
            blocked: true,
            scanRan: true,
            error: null,
            count: count($blocking),
            headline: $this->headline($blocking),
            top: $this->top($blocking),
        );
    }

    /**
     * @param  array{counts?: array<string, array<string, int>>}  $summary
     * @return list<string>
     */
    public function logLines(GateResult $result, array $summary): array
    {
        $lines = [];

        if (isset($summary['counts'])) {
            $lines[] = $this->countsLine($summary['counts']);
        }

        if ($result->headline !== '' && ($result->blocked || $result->error !== null)) {
            $lines[] = $result->headline;
        }

        if ($result->blocked && $result->error === null) {
            foreach (array_slice($result->top, 0, self::TOP_LIMIT) as $row) {
                $fix = $row['fixed_in'] === null ? '  fixed in a newer image' : " → {$row['fixed_in']}";
                $lines[] = "  {$row['id']}  {$row['severity']}  {$row['package']} {$row['version']}{$fix}".($row['kev'] ? '  known exploited' : '');
            }

            $lines[] = 'See the Vulnerabilities page for all results.';
        }

        return $lines;
    }

    /**
     * @param  array<string, array<string, int>>  $counts
     */
    private function countsLine(array $counts): string
    {
        $parts = [];

        foreach (self::LOG_SEVERITIES as $severity) {
            $n = (int) ($counts[ImageScanFixState::FixableNow->value][$severity] ?? 0);

            if ($n > 0) {
                $parts[] = "{$n} {$severity} with a fix";
            }
        }

        $without = array_sum($counts[ImageScanFixState::NotReleased->value] ?? [])
            + array_sum($counts[ImageScanFixState::NoFix->value] ?? []);

        if ($parts === []) {
            return 'Image scan: nothing to fix now'.($without > 0 ? ", {$without} without a fix" : '').'.';
        }

        return 'Image scan: '.implode(', ', $parts).($without > 0 ? ", {$without} without a fix" : '').'.';
    }

    /**
     * @return list<Finding>
     */
    private function blockingFindings(ImageScan $scan, ResolvedImageScanPolicy $policy, IgnoreMatcher $ignores): array
    {
        $classify = self::classifierFor($scan);
        $blocking = [];

        foreach ((array) $scan->findings as $row) {
            $finding = Finding::fromArray((array) $row);

            if ($ignores->matches($finding) !== null || ! $policy->alertLevel->includes($finding->severity)) {
                continue;
            }

            if ($classify($finding) === ImageScanFixState::FixableNow) {
                $blocking[] = $finding;
            }
        }

        return $blocking;
    }

    /**
     * The one way to classify the findings of a scan. The gate and the summary writer both use it, so the counts and the block never differ.
     *
     * @return \Closure(Finding): ImageScanFixState
     */
    public static function classifierFor(ImageScan $scan): \Closure
    {
        $classifier = new FixClassifier;
        $buildsImage = (bool) $scan->builds_image;
        $isBuilderImage = self::isBuilderImage($scan);
        $candidateFixedIds = self::candidateFixedIds($scan);

        return fn (Finding $finding): ImageScanFixState => $classifier->classify($finding, $buildsImage, $isBuilderImage, $candidateFixedIds);
    }

    private static function isBuilderImage(ImageScan $scan): bool
    {
        $resource = $scan->resource;

        return $resource instanceof Application && in_array($resource->build_pack, [
            BuildPackTypes::NIXPACKS->value,
            BuildPackTypes::RAILPACK->value,
            BuildPackTypes::STATIC->value,
        ], true);
    }

    /**
     * @return list<string>
     */
    private static function candidateFixedIds(ImageScan $scan): array
    {
        $ids = [];

        foreach ($scan->candidateEntries() as $candidate) {
            if (($candidate['better'] ?? false) === true) {
                foreach ((array) ($candidate['fixed_ids'] ?? []) as $id) {
                    $ids[] = (string) $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<Finding>  $blocking
     */
    private function headline(array $blocking): string
    {
        $parts = [];

        foreach (self::HEADLINE_SEVERITIES as $severity) {
            $n = count(array_filter($blocking, fn (Finding $finding) => strtolower($finding->severity) === $severity));

            if ($n > 0) {
                $parts[] = "{$n} {$severity}";
            }
        }

        $last = array_pop($parts);
        $list = $parts === [] ? $last : implode(', ', $parts).' and '.$last;

        return "Deploy blocked: {$list} ".(count($blocking) === 1 ? 'vulnerability' : 'vulnerabilities').' with a fix.';
    }

    /**
     * @param  list<Finding>  $blocking
     * @return list<array{id: string, severity: string, package: string, version: string, fixed_in: ?string, kev: bool}>
     */
    private function top(array $blocking): array
    {
        $rank = ['critical' => 3, 'high' => 2, 'medium' => 1];

        usort($blocking, fn (Finding $a, Finding $b) => [$b->kev, $rank[strtolower($b->severity)] ?? 0, $b->risk ?? 0.0]
            <=> [$a->kev, $rank[strtolower($a->severity)] ?? 0, $a->risk ?? 0.0]);

        return array_map(fn (Finding $finding) => [
            'id' => $finding->id,
            'severity' => strtolower($finding->severity),
            'package' => $finding->package,
            'version' => $finding->version,
            'fixed_in' => $finding->fixedIn,
            'kev' => $finding->kev,
        ], array_slice($blocking, 0, self::TOP_LIMIT));
    }
}
