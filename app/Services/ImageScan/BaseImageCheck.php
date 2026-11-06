<?php

namespace App\Services\ImageScan;

use App\Actions\ImageScan\RefreshCandidates;
use App\Models\ImageScan;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The newer base image of a Dockerfile build (spec 7.4, 15.4), from the stored base check of a scan.
 */
final class BaseImageCheck
{
    /**
     * A better base image for the final `FROM` of a Dockerfile build (spec 7.4, 15.4): of the relevant findings
     * that the current base image has too (m), how many the newer tag no longer has (n). Both are counted on the
     * current findings and ignores, with the IDs stored by the last check. Null without a better line candidate that
     * fixes one; failures of the base check are not shown.
     *
     * @return array{from: string, to: string, fixed: int, total: int}|null
     */
    public static function baseImage(ImageScan $scan): ?array
    {
        $better = self::betterBase($scan);
        $owner = $scan->resource;
        if ($better === null || ! $owner instanceof Model) {
            return null;
        }

        $base = (array) data_get($scan->candidates, 'base');
        $from = $better['from'];
        $attributableIds = array_flip(array_map(fn ($id): string => mb_strtolower((string) $id), (array) ($base['attributable_ids'] ?? [])));
        $fixedIds = array_flip(array_map(fn ($id): string => mb_strtolower((string) $id), (array) ($better['entry']['fixed_ids'] ?? [])));
        $has = fn (Finding $finding, array $ids): bool => collect([$finding->id, ...$finding->aliases])->contains(fn (string $id): bool => isset($ids[mb_strtolower($id)]));
        $attributable = array_filter(RefreshCandidates::relevantFindings($scan, $owner), fn (Finding $finding): bool => $has($finding, $attributableIds));
        $total = count($attributable);
        $fixed = count(array_filter($attributable, fn (Finding $finding): bool => $has($finding, $fixedIds)));
        if ($total === 0 || $fixed === 0) {
            return null;
        }

        return [
            'from' => $from,
            'to' => $better['to'],
            'fixed' => $fixed,
            'total' => $total,
        ];
    }

    /**
     * The better `line` candidate of the base check, when the check is for the current base image (`base_image_ref`)
     * and the candidate is a newer tag of the same image. `to` keeps the registry and name as the Dockerfile writes them.
     *
     * @return array{from: string, to: string, entry: array<string, mixed>}|null
     */
    public static function betterBase(ImageScan $scan): ?array
    {
        $from = (string) $scan->base_image_ref;
        $base = data_get($scan->candidates, 'base');
        if ($from === '' || ! is_array($base) || ($base['status'] ?? null) !== 'ok' || ($base['image'] ?? null) !== $from) {
            return null;
        }

        $entry = collect(is_array($base['entries'] ?? null) ? $base['entries'] : [])
            ->first(fn ($entry): bool => is_array($entry) && ($entry['kind'] ?? null) === 'line' && ($entry['better'] ?? false) === true && is_string($entry['ref'] ?? null));
        if ($entry === null) {
            return null;
        }

        try {
            $current = ImageRef::parse($from);
            $candidate = ImageRef::parse($entry['ref']);
        } catch (InvalidArgumentException) {
            return null;
        }
        if ($current->tag === null || $current->digest !== null || $candidate->tag === null || $candidate->tag === $current->tag
            || $candidate->qualifiedName() !== $current->qualifiedName()) {
            return null;
        }

        return [
            'from' => $from,
            'to' => substr($from, 0, (int) strrpos($from, ':')).':'.$candidate->tag,
            'entry' => $entry,
        ];
    }
}
