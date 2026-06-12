<?php

namespace App\Http\Controllers\Webhook\Bitbucket;

use Illuminate\Support\Collection;

class BitbucketRepositoryIdentifiers
{
    /**
     * @return array<int, string>
     */
    public static function fromPayload(Collection $payload): array
    {
        $identifiers = collect();

        $fullName = data_get($payload, 'repository.full_name');
        if (filled($fullName)) {
            $identifiers->push($fullName);
        }

        $repository = data_get($payload, 'repository')
            ?? data_get($payload, 'pullRequest.toRef.repository')
            ?? data_get($payload, 'pullRequest.fromRef.repository')
            ?? data_get($payload, 'pullrequest.toRef.repository')
            ?? data_get($payload, 'pullrequest.fromRef.repository');

        if (! is_array($repository)) {
            return $identifiers->unique()->filter()->values()->all();
        }

        $projectKey = data_get($repository, 'project.key');
        $slug = data_get($repository, 'slug');

        if (filled($projectKey) && filled($slug)) {
            $identifiers->push("{$projectKey}/{$slug}");
            $identifiers->push(strtolower((string) $projectKey).'/'.$slug);
        }

        if (filled($slug)) {
            $identifiers->push($slug);
        }

        foreach (data_get($repository, 'links.clone', []) as $clone) {
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
            $identifiers->push(end($segments));
        }

        return $identifiers->unique()->filter()->values()->all();
    }
}
