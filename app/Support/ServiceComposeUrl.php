<?php

namespace App\Support;

class ServiceComposeUrl
{
    /**
     * Validate a comma-separated URL string like `urls[].url` on PATCH /services/{uuid}.
     *
     * @return array{errors: array<int, string>, normalized: ?string}
     */
    public static function validateUrlString(?string $urlValue, bool $forceDomainOverride = false): array
    {
        $errors = [];

        if ($urlValue === null || $urlValue === '') {
            return ['errors' => [], 'normalized' => null];
        }

        $urls = str($urlValue)
            ->replaceStart(',', '')
            ->replaceEnd(',', '')
            ->trim()
            ->explode(',')
            ->map(fn ($url) => trim((string) $url))
            ->filter();

        foreach ($urls as $url) {
            if (! filter_var($url, FILTER_VALIDATE_URL)) {
                $errors[] = "Invalid URL: {$url}";
            }
            $scheme = parse_url($url, PHP_URL_SCHEME) ?? '';
            if (! in_array(strtolower($scheme), ['http', 'https'], true)) {
                $errors[] = "Invalid URL scheme: {$scheme} for URL: {$url}. Only http and https are supported.";
            }
        }

        $duplicates = $urls->duplicates()->unique()->values();
        if ($duplicates->isNotEmpty() && ! $forceDomainOverride) {
            $errors[] = 'The current request contains duplicate URLs: '.implode(', ', $duplicates->toArray()).'. Use force_domain_override=true to proceed.';
        }

        if (count($errors) > 0) {
            return ['errors' => $errors, 'normalized' => null];
        }

        $normalized = $urls
            ->map(fn ($u) => str($u)->lower()->value())
            ->unique()
            ->filter(fn ($u) => filled($u))
            ->implode(',');

        return ['errors' => [], 'normalized' => $normalized !== '' ? $normalized : null];
    }
}
