<?php

namespace App\Http\Controllers\Webhook\Bitbucket;

use Illuminate\Support\Collection;

class BitbucketRepositoryIdentifiers
{
    /**
     * Each identifier costs one database query before the signature check,
     * so an unsigned payload must not send an unbounded number of them.
     */
    private const MAX_IDENTIFIERS = 10;

    /**
     * @return array<int, string>
     */
    public static function fromPayload(Collection $payload): array
    {
        // Bitbucket Cloud payloads have `full_name`. Use only that, so Cloud
        // matching stays the same as before Data Center support.
        $fullName = data_get($payload, 'repository.full_name');
        if (is_string($fullName) && $fullName !== '') {
            return [$fullName];
        }

        $identifiers = collect();

        $repository = data_get($payload, 'repository')
            ?? data_get($payload, 'pullRequest.toRef.repository')
            ?? data_get($payload, 'pullRequest.fromRef.repository')
            ?? data_get($payload, 'pullrequest.toRef.repository')
            ?? data_get($payload, 'pullrequest.fromRef.repository');

        if (! is_array($repository)) {
            return $identifiers->unique()->filter()->take(self::MAX_IDENTIFIERS)->values()->all();
        }

        $projectKey = data_get($repository, 'project.key');
        $slug = data_get($repository, 'slug');

        if (is_string($projectKey) && $projectKey !== '' && is_string($slug) && $slug !== '') {
            $identifiers->push("{$projectKey}/{$slug}");
        }

        $clones = data_get($repository, 'links.clone');
        foreach (is_array($clones) ? $clones : [] as $clone) {
            $href = data_get($clone, 'href');
            if (! is_string($href) || $href === '') {
                continue;
            }

            if (preg_match('#[:/]([^/]+/[^/]+?)(?:\.git)?$#', $href, $matches)) {
                $identifiers->push($matches[1]);
            }

            $path = preg_replace('/\.git$/', '', parse_url($href, PHP_URL_PATH) ?? '');
            $path = trim(str_replace(':', '/', (string) $path), '/');
            if ($path === '') {
                continue;
            }

            $segments = explode('/', $path);
            if (count($segments) >= 2) {
                $identifiers->push(implode('/', array_slice($segments, -2)));
            }
        }

        return $identifiers->unique()->filter()->take(self::MAX_IDENTIFIERS)->values()->all();
    }
}
