<?php

namespace App\Services\ImageScan;

use App\Enums\SecurityStatusType;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The security status of one image or one resource (spec 15.6), with what the main card shows (15.7).
 *
 * Counts follow the status the actions give (the status without the check reasons):
 * - update_now: `problemCount` is the relevant findings with an exploit signal that have an action, `fixedByPrimary`
 *   how many of them the primary action fixes, `anyExploited` whether one of them is `exploited`.
 * - update_available: `problemCount` is the relevant critical and high findings that have an action
 *   (`criticalCount` + `highCount`), `fixedByPrimary` how many of them the primary action fixes.
 * - no_fix: `problemCount` is the relevant findings with an exploit signal without an action, `anyExploited` whether
 *   one of them is `exploited`.
 *
 * `exploitedCount` is all relevant findings with an exploit signal, with or without an action; so
 * `exploitedCount - problemCount` of update_now (or `exploitedCount` of update_available) have no fix yet.
 * `primaryCritical` and `primaryHigh` are the critical and high fixes of the primary action.
 *
 * An action that leaves an end-of-life OS counts it as one critical relevant fix (`problemCount`, `criticalCount`,
 * `fixedByPrimary`, `primaryCritical`); `eol.fixed` says so. It is not in `why` or `moreFixed`.
 *
 * `builderImage`: every image whose findings drive no_fix is built by a builder (Nixpacks, Railpack, static), so the
 * no_fix text is the builder text.
 *
 * `checkedAt` is the time of the oldest current scan of the resource (null when one has no time); `stale` uses it.
 *
 * `checkReason` is the check_needed reason (`images_unchecked` {checked, total}, `stale` {time}, `db_outdated`,
 * `image_changed`, `versions_unchecked`; context in `checkContext`), or the not_checked reason (`instance`, `server`,
 * `application` for scanning off, `not_scanned`).
 *
 * `why` lists up to 3 findings the primary action fixes (no_fix: the findings with an exploit signal that have no
 * action); `moreFixed` is the other relevant findings the primary action fixes.
 *
 * `facts` is what `SecurityStatus` needs to combine the statuses of several images without the findings: the status
 * the actions give, the check reasons that do not depend on the time, counts, and per action (in the order of
 * `actions`) what it fixes and its why-list.
 */
final readonly class ResourceSecurity
{
    /**
     * @param  array<string, int|string|null>  $checkContext
     * @param  list<SecurityAction>  $actions  The primary action first.
     * @param  list<array{title: string, display_id: string, link: ?string, score: ?float, severity: string, signal: ?string}>  $why
     * @param  array{name: string, version: string, fixed: bool}|null  $eol  The end-of-life OS, and whether an action leaves it.
     * @param  array<string, mixed>  $facts
     */
    public function __construct(
        public SecurityStatusType $status,
        public ?string $checkReason = null,
        public array $checkContext = [],
        public array $actions = [],
        public array $why = [],
        public int $moreFixed = 0,
        public bool $exploitedWithoutFix = false,
        public int $problemCount = 0,
        public int $exploitedCount = 0,
        public bool $anyExploited = false,
        public int $criticalCount = 0,
        public int $highCount = 0,
        public int $fixedByPrimary = 0,
        public int $primaryCritical = 0,
        public int $primaryHigh = 0,
        public ?array $eol = null,
        public ?CarbonImmutable $checkedAt = null,
        public array $facts = [],
        public bool $builderImage = false,
    ) {}

    public function primary(): ?SecurityAction
    {
        return $this->actions[0] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'check_reason' => $this->checkReason,
            'check_context' => $this->checkContext,
            'actions' => array_map(fn (SecurityAction $action): array => $action->toArray(), $this->actions),
            'why' => $this->why,
            'more_fixed' => $this->moreFixed,
            'exploited_without_fix' => $this->exploitedWithoutFix,
            'problem_count' => $this->problemCount,
            'exploited_count' => $this->exploitedCount,
            'any_exploited' => $this->anyExploited,
            'critical_count' => $this->criticalCount,
            'high_count' => $this->highCount,
            'fixed_by_primary' => $this->fixedByPrimary,
            'primary_critical' => $this->primaryCritical,
            'primary_high' => $this->primaryHigh,
            'eol' => $this->eol,
            'checked_at' => $this->checkedAt?->toIso8601String(),
            'facts' => $this->facts,
            'builder_image' => $this->builderImage,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $eol = is_array($data['eol'] ?? null) ? $data['eol'] : null;
        $facts = (array) ($data['facts'] ?? []);
        $stats = (array) ($facts['action_stats'] ?? []);
        $actions = [];
        $actionStats = [];
        foreach (array_values((array) ($data['actions'] ?? [])) as $index => $actionData) {
            $action = is_array($actionData) ? SecurityAction::fromArray($actionData) : null;
            if ($action !== null) {
                $actions[] = $action;
                $actionStats[] = $stats[$index] ?? [];
            }
        }
        if (array_key_exists('action_stats', $facts)) {
            $facts['action_stats'] = $actionStats;
        }

        return new self(
            status: SecurityStatusType::tryFrom((string) ($data['status'] ?? '')) ?? SecurityStatusType::NotChecked,
            checkReason: isset($data['check_reason']) ? (string) $data['check_reason'] : null,
            checkContext: (array) ($data['check_context'] ?? []),
            actions: $actions,
            why: array_values(array_map(fn ($entry): array => self::whyEntry((array) $entry), (array) ($data['why'] ?? []))),
            moreFixed: (int) ($data['more_fixed'] ?? 0),
            exploitedWithoutFix: (bool) ($data['exploited_without_fix'] ?? false),
            problemCount: (int) ($data['problem_count'] ?? 0),
            exploitedCount: (int) ($data['exploited_count'] ?? 0),
            anyExploited: (bool) ($data['any_exploited'] ?? false),
            criticalCount: (int) ($data['critical_count'] ?? 0),
            highCount: (int) ($data['high_count'] ?? 0),
            fixedByPrimary: (int) ($data['fixed_by_primary'] ?? 0),
            primaryCritical: (int) ($data['primary_critical'] ?? 0),
            primaryHigh: (int) ($data['primary_high'] ?? 0),
            eol: $eol === null ? null : ['name' => (string) ($eol['name'] ?? ''), 'version' => (string) ($eol['version'] ?? ''), 'fixed' => (bool) ($eol['fixed'] ?? false)],
            checkedAt: self::time($data['checked_at'] ?? null),
            facts: $facts,
            builderImage: (bool) ($data['builder_image'] ?? false),
        );
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{title: string, display_id: string, link: ?string, score: ?float, severity: string, signal: ?string}
     */
    public static function whyEntry(array $entry): array
    {
        return [
            'title' => (string) ($entry['title'] ?? ''),
            'display_id' => (string) ($entry['display_id'] ?? ''),
            'link' => isset($entry['link']) ? (string) $entry['link'] : null,
            'score' => is_numeric($entry['score'] ?? null) ? (float) $entry['score'] : null,
            'severity' => (string) ($entry['severity'] ?? 'unknown'),
            'signal' => isset($entry['signal']) ? (string) $entry['signal'] : null,
        ];
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
