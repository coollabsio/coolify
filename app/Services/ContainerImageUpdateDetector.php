<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Http;

class ContainerImageUpdateDetector
{
    protected array $registryCache = [];

    public function detect(string $imageReference, ?Server $server = null): ?array
    {
        $parser = (new DockerImageParser)->parse($imageReference);

        if ($parser->isImageHash()) {
            return null;
        }

        $repository = $parser->getFullImageNameWithoutTag();
        $currentTag = $parser->getTag();

        if (str_contains($currentTag, '$') || $this->isCustomRegistry($repository)) {
            return null;
        }

        $tags = $this->fetchTags($repository);
        if (empty($tags)) {
            return null;
        }

        $registryUrl = $this->getRegistryUrl($imageReference);
        $registryDigest = $this->findTagDigest($tags, $currentTag);
        $localDigest = $server ? $this->resolveLocalDigest($server, $imageReference) : null;

        if ($localDigest && $registryDigest && $localDigest !== $registryDigest) {
            $resolvedVersion = $this->resolveVersionForDigest($tags, $registryDigest);
            $summary = $resolvedVersion
                ? "{$imageReference} (new digest available, current registry release: {$resolvedVersion})"
                : "{$imageReference} (new digest available)";

            return [
                'mode' => 'digest',
                'current_reference' => $imageReference,
                'target_reference' => $repository.':'.$currentTag,
                'summary' => $summary,
                'registry_url' => $registryUrl,
                'current_digest' => $localDigest,
                'target_digest' => $registryDigest,
                'resolved_target' => $resolvedVersion,
            ];
        }

        if (strtolower($currentTag) === 'latest') {
            return null;
        }

        $targetReference = $this->findBestTag($tags, $currentTag, $repository);
        if (! $targetReference || $targetReference === $imageReference) {
            return null;
        }

        return [
            'mode' => 'tag',
            'current_reference' => $imageReference,
            'target_reference' => $targetReference,
            'summary' => "{$imageReference} -> {$targetReference}",
            'registry_url' => $registryUrl,
            'current_digest' => $localDigest,
            'target_digest' => null,
            'resolved_target' => $targetReference,
        ];
    }

    protected function resolveLocalDigest(Server $server, string $imageReference): ?string
    {
        if (! $server->isFunctional()) {
            return null;
        }

        $output = instant_remote_process([
            "docker image inspect --format '{{join .RepoDigests \",\"}}' ".escapeShellValue($imageReference).' 2>/dev/null || true',
        ], $server, false);

        if (! is_string($output) || blank(trim($output))) {
            return null;
        }

        preg_match('/sha256:[a-f0-9]{64}/i', $output, $matches);

        return $matches[0] ?? null;
    }

    protected function fetchTags(string $repository): array
    {
        if (isset($this->registryCache[$repository])) {
            return $this->registryCache[$repository];
        }

        $tags = match (true) {
            str_starts_with($repository, 'ghcr.io/') => $this->fetchGhcrTags($repository),
            str_starts_with($repository, 'quay.io/') => $this->fetchQuayTags($repository),
            str_starts_with($repository, 'codeberg.org/') => $this->fetchCodebergTags($repository),
            default => $this->fetchDockerHubTags($repository),
        };

        return $this->registryCache[$repository] = $tags;
    }

    protected function fetchDockerHubTags(string $repository): array
    {
        try {
            $cleanRepo = str_replace(['index.docker.io/', 'docker.io/', 'lscr.io/'], '', $repository);
            if (! str_contains($cleanRepo, '/')) {
                $cleanRepo = "library/{$cleanRepo}";
            }

            $response = Http::timeout(10)->get(
                "https://hub.docker.com/v2/repositories/{$cleanRepo}/tags",
                ['page_size' => 100, 'ordering' => 'last_updated']
            );

            if (! $response->successful()) {
                return [];
            }

            return collect($response->json('results', []))
                ->map(fn ($tag) => [
                    'name' => data_get($tag, 'name'),
                    'digest' => data_get($tag, 'digest') ?? data_get($tag, 'images.0.digest'),
                ])
                ->filter(fn ($tag) => filled($tag['name']))
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    protected function fetchGhcrTags(string $repository): array
    {
        try {
            $parts = explode('/', str_replace('ghcr.io/', '', $repository));
            if (count($parts) < 2) {
                return [];
            }

            $owner = $parts[0];
            $package = $parts[1];

            $response = Http::timeout(10)
                ->withHeaders(['Accept' => 'application/vnd.github.v3+json'])
                ->get("https://api.github.com/users/{$owner}/packages/container/{$package}/versions", ['per_page' => 100]);

            if (! $response->successful()) {
                return [];
            }

            return collect($response->json())
                ->flatMap(function ($version) {
                    $digest = data_get($version, 'name');
                    $tags = data_get($version, 'metadata.container.tags', []);

                    return collect($tags)->map(fn ($tag) => [
                        'name' => $tag,
                        'digest' => $digest,
                    ]);
                })
                ->filter(fn ($tag) => filled($tag['name']))
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    protected function fetchQuayTags(string $repository): array
    {
        try {
            $cleanRepo = str_replace('quay.io/', '', $repository);
            $response = Http::timeout(10)->get("https://quay.io/api/v1/repository/{$cleanRepo}/tag/", ['limit' => 100]);

            if (! $response->successful()) {
                return [];
            }

            return collect($response->json('tags', []))
                ->map(fn ($tag) => [
                    'name' => data_get($tag, 'name'),
                    'digest' => data_get($tag, 'manifest_digest'),
                ])
                ->filter(fn ($tag) => filled($tag['name']))
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    protected function fetchCodebergTags(string $repository): array
    {
        try {
            $cleanRepo = str_replace('codeberg.org/', '', $repository);
            $parts = explode('/', $cleanRepo);
            if (count($parts) < 2) {
                return [];
            }

            $owner = $parts[0];
            $package = $parts[1];

            $response = Http::timeout(10)->get("https://codeberg.org/api/packages/{$owner}/container/{$package}");
            if (! $response->successful()) {
                return [];
            }

            return collect($response->json('versions', []))
                ->map(fn ($version) => [
                    'name' => data_get($version, 'name'),
                    'digest' => null,
                ])
                ->filter(fn ($tag) => filled($tag['name']))
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    protected function findTagDigest(array $tags, string $targetTag): ?string
    {
        foreach ($tags as $tag) {
            if (data_get($tag, 'name') === $targetTag) {
                return data_get($tag, 'digest');
            }
        }

        return null;
    }

    protected function resolveVersionForDigest(array $tags, string $digest): ?string
    {
        $matchingVersions = collect($tags)
            ->filter(fn ($tag) => data_get($tag, 'digest') === $digest)
            ->pluck('name')
            ->filter(fn ($name) => preg_match('/^v?\d+\.\d+(\.\d+)?(\.\d+)?$/', $name) === 1)
            ->values()
            ->all();

        if (empty($matchingVersions)) {
            return null;
        }

        return $this->preferShorterVersion($matchingVersions);
    }

    protected function findBestTag(array $tags, string $currentTag, string $repository): ?string
    {
        if (empty($tags)) {
            return null;
        }

        if (preg_match('/^RELEASE\.\d{4}-\d{2}-\d{2}/', $currentTag)) {
            $releaseTags = array_filter($tags, fn ($tag) => preg_match('/^RELEASE\.\d{4}-\d{2}-\d{2}/', data_get($tag, 'name', '')));
            if (! empty($releaseTags)) {
                $sorted = $this->sortSemanticVersions(array_column($releaseTags, 'name'));
                $latestRelease = $sorted[0] ?? null;
                if ($latestRelease && $latestRelease !== $currentTag) {
                    return $repository.':'.$latestRelease;
                }
            }

            return null;
        }

        if (preg_match('/^\d{4}\.\d{2}\.\d{2}/', $currentTag)) {
            $dateTags = array_filter($tags, fn ($tag) => preg_match('/^\d{4}\.\d{2}\.\d{2}/', data_get($tag, 'name', '')));
            if (! empty($dateTags)) {
                $sorted = $this->sortSemanticVersions(array_column($dateTags, 'name'));
                $latestDate = $sorted[0] ?? null;
                if ($latestDate && $latestDate !== $currentTag) {
                    return $repository.':'.$latestDate;
                }
            }

            return null;
        }

        if (preg_match('/^v?\d+$/', $currentTag)) {
            $cleanTag = ltrim($currentTag, 'v');
            $matchingTags = array_filter($tags, function ($tag) use ($cleanTag) {
                return preg_match("/^v?{$cleanTag}(\\.\\d+)?(\\.\\d+)?$/", data_get($tag, 'name', '')) === 1;
            });
            if (! empty($matchingTags)) {
                $bestVersion = $this->preferShorterVersion(array_column($matchingTags, 'name'));
                if ($bestVersion !== $currentTag) {
                    return $repository.':'.$bestVersion;
                }
            }

            return null;
        }

        if (preg_match('/^v?\d+\.\d+(\.\d+)?$/', $currentTag)) {
            $cleanTag = ltrim($currentTag, 'v');
            $parts = explode('.', $cleanTag);
            $majorMinor = $parts[0].'.'.$parts[1];
            $matchingTags = array_filter($tags, function ($tag) use ($majorMinor) {
                return str_starts_with(ltrim(data_get($tag, 'name', ''), 'v'), $majorMinor);
            });
            if (! empty($matchingTags)) {
                $bestVersion = $this->preferShorterVersion(array_column($matchingTags, 'name'));
                if ($bestVersion !== $currentTag && version_compare(ltrim($bestVersion, 'v'), ltrim($currentTag, 'v'), '>')) {
                    return $repository.':'.$bestVersion;
                }
            }

            return null;
        }

        if (in_array($currentTag, ['stable', 'lts', 'edge'], true)) {
            return null;
        }

        return null;
    }

    protected function preferShorterVersion(array $versions): string
    {
        $sorted = $this->sortSemanticVersions($versions);
        $highest = $sorted[0] ?? '';
        if ($highest === '') {
            return '';
        }

        $parts = explode('.', $highest);
        if (count($parts) === 3) {
            $majorMinor = $parts[0].'.'.$parts[1];
            if (in_array($majorMinor, $versions, true)) {
                return $majorMinor;
            }
        }
        if (count($parts) >= 2) {
            $major = $parts[0];
            if (in_array($major, $versions, true)) {
                return $major;
            }
        }

        return $highest;
    }

    protected function sortSemanticVersions(array $versions): array
    {
        usort($versions, function ($a, $b) {
            $dateA = preg_match('/^(\d{4})\.(\d{2})\.(\d{1,2})/', $a, $matchesA);
            $dateB = preg_match('/^(\d{4})\.(\d{2})\.(\d{1,2})/', $b, $matchesB);

            if ($dateA && $dateB) {
                $normalizedA = $matchesA[1].$matchesA[2].str_pad($matchesA[3], 2, '0', STR_PAD_LEFT);
                $normalizedB = $matchesB[1].$matchesB[2].str_pad($matchesB[3], 2, '0', STR_PAD_LEFT);

                return strcmp($normalizedB, $normalizedA);
            }

            $releaseA = preg_match('/^RELEASE\.(\d{4})-(\d{2})-(\d{2})T(\d{2})-(\d{2})-(\d{2})Z/', $a, $releaseMatchesA);
            $releaseB = preg_match('/^RELEASE\.(\d{4})-(\d{2})-(\d{2})T(\d{2})-(\d{2})-(\d{2})Z/', $b, $releaseMatchesB);

            if ($releaseA && $releaseB) {
                $normalizedA = implode('', array_slice($releaseMatchesA, 1, 6));
                $normalizedB = implode('', array_slice($releaseMatchesB, 1, 6));

                return strcmp($normalizedB, $normalizedA);
            }

            return version_compare(ltrim($b, 'v'), ltrim($a, 'v'));
        });

        return $versions;
    }

    protected function isCustomRegistry(string $repository): bool
    {
        foreach ([
            'docker.elastic.co/',
            'docker.n8n.io/',
            'docker.flipt.io/',
            'docker.getoutline.com/',
            'cr.weaviate.io/',
            'downloads.unstructured.io/',
            'budibase.docker.scarf.sh/',
            'calcom.docker.scarf.sh/',
            'code.forgejo.org/',
            'registry.supertokens.io/',
            'registry.rocket.chat/',
            'nabo.codimd.dev/',
            'gcr.io/',
        ] as $registry) {
            if (str_starts_with($repository, $registry)) {
                return true;
            }
        }

        return false;
    }

    protected function getRegistryUrl(string $imageReference): ?string
    {
        $parser = (new DockerImageParser)->parse($imageReference);
        $repository = $parser->getFullImageNameWithoutTag();

        if (str_starts_with($repository, 'ghcr.io/')) {
            $parts = explode('/', str_replace('ghcr.io/', '', $repository));
            if (count($parts) >= 2) {
                return "https://github.com/{$parts[0]}/{$parts[1]}/pkgs/container/{$parts[1]}";
            }
        }

        if (str_starts_with($repository, 'quay.io/')) {
            return 'https://quay.io/repository/'.str_replace('quay.io/', '', $repository).'?tab=tags';
        }

        if (str_starts_with($repository, 'codeberg.org/')) {
            $parts = explode('/', str_replace('codeberg.org/', '', $repository));
            if (count($parts) >= 2) {
                return "https://codeberg.org/{$parts[0]}/-/packages/container/{$parts[1]}";
            }
        }

        $cleanRepo = str_replace(['index.docker.io/', 'docker.io/', 'lscr.io/'], '', $repository);

        return str_contains($cleanRepo, '/')
            ? "https://hub.docker.com/r/{$cleanRepo}/tags"
            : "https://hub.docker.com/_/{$cleanRepo}/tags";
    }
}
