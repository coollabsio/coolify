<?php

namespace App\Services\ImageScan;

/**
 * Picks newer version tags of an image from a registry tag list (spec 7.2 and the candidate rules of spec 11).
 *
 * A tag is `prefix + numeric version + suffix` (`v1.2.3`, `15.0-alpine`). Candidates keep the prefix, the suffix,
 * and the number of version parts of the current tag. Never uses Grype's suggested version.
 */
final class TagSelector
{
    public const MAX_TAGS = 10_000;

    private const PRE_RELEASE = '/(?:\A|[._-])(?:rc|beta|alpha|dev|nightly|edge|preview|canary)\d*(?:\z|[._-])/i';

    /**
     * `line`: newest tag of the same major. When the current tag is floating (`15-alpine`, other tags such as
     * `15.4-alpine` extend it), that is the newest floating tag of the line, else the newest exact tag.
     * `patch`: for an exact tag with three or more version parts (`8.0.40`), the newest tag of the same major and minor
     * (`8.0.45`), the smallest change; null when it is the `line` tag.
     * `major`: newest tag of a higher major, for information only. All null for a tag that is not a version.
     *
     * @param  list<string>  $tags
     * @return array{line: ?string, patch: ?string, major: ?string, floating: bool}
     */
    public function select(string $currentTag, array $tags): array
    {
        $current = $this->parse($currentTag);
        if ($current === null) {
            return ['line' => null, 'patch' => null, 'major' => null, 'floating' => false];
        }

        $sameKind = [];
        foreach (array_slice($tags, 0, self::MAX_TAGS) as $tag) {
            $parsed = is_string($tag) ? $this->parse($tag) : null;
            if ($parsed !== null && $parsed['prefix'] === $current['prefix'] && $parsed['suffix'] === $current['suffix']) {
                $sameKind[$tag] = $parsed['parts'];
            }
        }

        $floating = false;
        foreach ($sameKind as $parts) {
            if (count($parts) > count($current['parts']) && array_slice($parts, 0, count($current['parts'])) === $current['parts']) {
                $floating = true;

                break;
            }
        }

        $sameShape = array_filter($sameKind, fn (array $parts) => count($parts) === count($current['parts']));
        $line = $this->newest(array_filter($sameShape, fn (array $parts) => $parts[0] === $current['parts'][0]
            && $this->compare($parts, $current['parts']) >= 0));
        if ($line === $currentTag && ! $floating) {
            $line = null;
        }

        $patch = null;
        if (! $floating && count($current['parts']) >= 3) {
            $patch = $this->newest(array_filter($sameShape, fn (array $parts) => $parts[0] === $current['parts'][0]
                && $parts[1] === $current['parts'][1] && $this->compare($parts, $current['parts']) > 0));
            $patch = $patch === $line ? null : $patch;
        }

        $higherMajor = fn (array $parts) => $parts[0] > $current['parts'][0];
        $major = $this->newest(array_filter($sameShape, $higherMajor)) ?? $this->newest(array_filter($sameKind, $higherMajor));

        return ['line' => $line, 'patch' => $patch, 'major' => $major, 'floating' => $floating];
    }

    /**
     * Whether a tag is a version that other tags can be compared with (`sha-3f2a1c`, `main`, `latest` are not).
     */
    public function isVersion(string $tag): bool
    {
        return $this->parse($tag) !== null;
    }

    /**
     * @return array{prefix: string, parts: list<int>, suffix: string}|null
     */
    private function parse(string $tag): ?array
    {
        if (! ImageRef::isValidTag($tag)
            || str_starts_with($tag, 'sha256-')
            || preg_match('/\.(sig|att|sbom)\z/', $tag) === 1
            || preg_match(self::PRE_RELEASE, $tag) === 1
            || preg_match('/\A(?<prefix>[^0-9]*)(?<version>[0-9]{1,9}(?:\.[0-9]{1,9})*)(?<suffix>(?:[-_+].*)?)\z/', $tag, $match) !== 1) {
            return null;
        }

        $prefix = $match['prefix'];
        if ($prefix !== '' && (preg_match('/[-_.v]\z/i', $prefix) !== 1 || preg_match('/\Asha[-_]?\z/i', $prefix) === 1)) {
            return null;
        }

        return [
            'prefix' => $prefix,
            'parts' => array_map('intval', explode('.', $match['version'])),
            'suffix' => $match['suffix'],
        ];
    }

    /**
     * @param  array<string, list<int>>  $tags
     */
    private function newest(array $tags): ?string
    {
        $newest = null;
        foreach ($tags as $tag => $parts) {
            if ($newest === null || $this->compare($parts, $tags[$newest]) > 0) {
                $newest = (string) $tag;
            }
        }

        return $newest;
    }

    /**
     * @param  list<int>  $a
     * @param  list<int>  $b
     */
    private function compare(array $a, array $b): int
    {
        $length = max(count($a), count($b));

        return array_pad($a, $length, 0) <=> array_pad($b, $length, 0);
    }
}
