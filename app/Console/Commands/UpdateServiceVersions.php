<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;

class UpdateServiceVersions extends Command
{
    protected $signature = 'services:update-versions
                            {--service= : Update specific service template}
                            {--dry-run : Show what would be updated without making changes}
                            {--registry= : Filter by registry (dockerhub, ghcr, quay, codeberg)}';

    protected $description = 'Update service template files with latest Docker image versions from registries';

    protected array $stats = [
        'total' => 0,
        'updated' => 0,
        'failed' => 0,
        'skipped' => 0,
    ];

    protected array $registryCache = [];

    protected array $majorVersionUpdates = [];

    public function handle(): int
    {
        $this->info('Starting service version update...');

        $templateFiles = $this->getTemplateFiles();

        $this->stats['total'] = count($templateFiles);

        foreach ($templateFiles as $file) {
            $this->processTemplate($file);
        }

        $this->newLine();
        $this->displayStats();

        return self::SUCCESS;
    }

    protected function getTemplateFiles(): array
    {
        $pattern = base_path('templates/compose/*.yaml');
        $files = glob($pattern);

        if ($service = $this->option('service')) {
            $files = array_filter($files, fn ($file) => basename($file) === "$service.yaml");
        }

        return $files;
    }

    protected function processTemplate(string $filePath): void
    {
        $filename = basename($filePath);
        $this->info("Processing: {$filename}");

        try {
            $content = file_get_contents($filePath);
            $yaml = Yaml::parse($content);

            if (! isset($yaml['services'])) {
                $this->warn("  No services found in {$filename}");
                $this->stats['skipped']++;

                return;
            }

            $updated = false;
            $updatedYaml = $yaml;

            foreach ($yaml['services'] as $serviceName => $serviceConfig) {
                if (! isset($serviceConfig['image'])) {
                    continue;
                }

                $currentImage = $serviceConfig['image'];

                // Check if using 'latest' tag and log for manual review
                if (str_contains($currentImage, ':latest')) {
                    $registryUrl = $this->getRegistryUrl($currentImage);
                    $this->warn("  {$serviceName}: {$currentImage} (using 'latest' tag)");
                    if ($registryUrl) {
                        $this->line("    → Manual review: {$registryUrl}");
                    }
                }

                $latestVersion = $this->getLatestVersion($currentImage);

                if ($latestVersion && $latestVersion !== $currentImage) {
                    $this->line("  {$serviceName}: {$currentImage} → {$latestVersion}");
                    $updatedYaml['services'][$serviceName]['image'] = $latestVersion;
                    $updated = true;
                } else {
                    $this->line("  {$serviceName}: {$currentImage} (up to date)");
                }
            }

            if ($updated) {
                if (! $this->option('dry-run')) {
                    $this->updateYamlFile($filePath, $content, $updatedYaml);
                    $this->stats['updated']++;
                } else {
                    $this->warn('  [DRY RUN] Would update this file');
                    $this->stats['updated']++;
                }
            } else {
                $this->stats['skipped']++;
            }

        } catch (\Throwable $e) {
            $this->error("  Failed: {$e->getMessage()}");
            $this->stats['failed']++;
        }

        $this->newLine();
    }

    protected function getLatestVersion(string $image): ?string
    {
        // Parse the image string
        [$repository, $currentTag] = $this->parseImage($image);

        // Determine registry and fetch latest version
        $result = null;
        if (str_starts_with($repository, 'ghcr.io/')) {
            $result = $this->getGhcrLatestVersion($repository, $currentTag);
        } elseif (str_starts_with($repository, 'quay.io/')) {
            $result = $this->getQuayLatestVersion($repository, $currentTag);
        } elseif (str_starts_with($repository, 'codeberg.org/')) {
            $result = $this->getCodebergLatestVersion($repository, $currentTag);
        } elseif (str_starts_with($repository, 'lscr.io/')) {
            $result = $this->getDockerHubLatestVersion($repository, $currentTag);
        } elseif ($this->isCustomRegistry($repository)) {
            // Custom registries - skip for now, log warning
            $this->warn("  Skipping custom registry: {$repository}");
            $result = null;
        } else {
            // DockerHub (default registry - no prefix or docker.io/index.docker.io)
            $result = $this->getDockerHubLatestVersion($repository, $currentTag);
        }

        return $result;
    }

    protected function isCustomRegistry(string $repository): bool
    {
        // List of custom/private registries that we can't query
        $customRegistries = [
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
        ];

        foreach ($customRegistries as $registry) {
            if (str_starts_with($repository, $registry)) {
                return true;
            }
        }

        return false;
    }

    protected function getRegistryUrl(string $image): ?string
    {
        [$repository] = $this->parseImage($image);

        // GitHub Container Registry
        if (str_starts_with($repository, 'ghcr.io/')) {
            $parts = explode('/', str_replace('ghcr.io/', '', $repository));
            if (count($parts) >= 2) {
                return "https://github.com/{$parts[0]}/{$parts[1]}/pkgs/container/{$parts[1]}";
            }
        }

        // Quay.io
        if (str_starts_with($repository, 'quay.io/')) {
            $repo = str_replace('quay.io/', '', $repository);

            return "https://quay.io/repository/{$repo}?tab=tags";
        }

        // Codeberg
        if (str_starts_with($repository, 'codeberg.org/')) {
            $parts = explode('/', str_replace('codeberg.org/', '', $repository));
            if (count($parts) >= 2) {
                return "https://codeberg.org/{$parts[0]}/-/packages/container/{$parts[1]}";
            }
        }

        // Docker Hub
        $cleanRepo = str_replace(['index.docker.io/', 'docker.io/', 'lscr.io/'], '', $repository);
        if (! str_contains($cleanRepo, '/')) {
            // Official image
            return "https://hub.docker.com/_/{$cleanRepo}/tags";
        } else {
            // User/org image
            return "https://hub.docker.com/r/{$cleanRepo}/tags";
        }
    }

    protected function parseImage(string $image): array
    {
        if (str_contains($image, ':')) {
            [$repo, $tag] = explode(':', $image, 2);
        } else {
            $repo = $image;
            $tag = 'latest';
        }

        // Handle variables in tags
        if (str_contains($tag, '$')) {
            $tag = 'latest'; // Default to latest for variable tags
        }

        return [$repo, $tag];
    }

    protected function getDockerHubLatestVersion(string $repository, string $currentTag): ?string
    {
        try {
            // Check if we've already fetched tags for this repository
            if (! isset($this->registryCache[$repository.'_tags'])) {
                // Remove various registry prefixes
                $cleanRepo = $repository;
                $cleanRepo = str_replace('index.docker.io/', '', $cleanRepo);
                $cleanRepo = str_replace('docker.io/', '', $cleanRepo);
                $cleanRepo = str_replace('lscr.io/', '', $cleanRepo);

                // For official images (no /) add library prefix
                if (! str_contains($cleanRepo, '/')) {
                    $cleanRepo = "library/{$cleanRepo}";
                }

                $url = "https://hub.docker.com/v2/repositories/{$cleanRepo}/tags";

                $response = Http::timeout(10)->get($url, [
                    'page_size' => 100,
                    'ordering' => 'last_updated',
                ]);

                if (! $response->successful()) {
                    return null;
                }

                $data = $response->json();
                $tags = $data['results'] ?? [];

                // Cache the tags for this repository
                $this->registryCache[$repository.'_tags'] = $tags;
            } else {
                $this->line("    [cached] Using cached tags for {$repository}");
                $tags = $this->registryCache[$repository.'_tags'];
            }

            // Find the best matching tag
            return $this->findBestTag($tags, $currentTag, $repository);

        } catch (\Throwable $e) {
            $this->warn("  DockerHub API error for {$repository}: {$e->getMessage()}");

            return null;
        }
    }

    protected function findLatestTagDigest(array $tags, string $targetTag = 'latest'): ?string
    {
        // Find the digest/sha for the target tag (usually 'latest')
        foreach ($tags as $tag) {
            if ($tag['name'] === $targetTag) {
                return $tag['digest'] ?? $tag['images'][0]['digest'] ?? null;
            }
        }

        return null;
    }

    protected function findVersionTagsForDigest(array $tags, string $digest): array
    {
        // Find all semantic version tags that share the same digest
        $versionTags = [];

        foreach ($tags as $tag) {
            $tagDigest = $tag['digest'] ?? $tag['images'][0]['digest'] ?? null;

            if ($tagDigest === $digest) {
                $tagName = $tag['name'];
                // Only include semantic version tags
                if (preg_match('/^\d+\.\d+(\.\d+)?$/', $tagName)) {
                    $versionTags[] = $tagName;
                }
            }
        }

        return $versionTags;
    }

    protected function getGhcrLatestVersion(string $repository, string $currentTag): ?string
    {
        try {
            // GHCR doesn't have a public API for listing tags without auth
            // We'll try to fetch the package metadata via GitHub API
            $parts = explode('/', str_replace('ghcr.io/', '', $repository));

            if (count($parts) < 2) {
                return null;
            }

            $owner = $parts[0];
            $package = $parts[1];

            // Try GitHub Container Registry API
            $url = "https://api.github.com/users/{$owner}/packages/container/{$package}/versions";

            $response = Http::timeout(10)
                ->withHeaders([
                    'Accept' => 'application/vnd.github.v3+json',
                ])
                ->get($url, ['per_page' => 100]);

            if (! $response->successful()) {
                // Most GHCR packages require authentication
                if ($currentTag === 'latest') {
                    $this->warn('    ⚠ GHCR requires authentication - manual review needed');
                }

                return null;
            }

            $versions = $response->json();
            $tags = [];

            // Build tags array with digest information
            foreach ($versions as $version) {
                $digest = $version['name'] ?? null; // This is the SHA digest

                if (isset($version['metadata']['container']['tags'])) {
                    foreach ($version['metadata']['container']['tags'] as $tag) {
                        $tags[] = [
                            'name' => $tag,
                            'digest' => $digest,
                        ];
                    }
                }
            }

            return $this->findBestTag($tags, $currentTag, $repository);

        } catch (\Throwable $e) {
            $this->warn("  GHCR API error for {$repository}: {$e->getMessage()}");

            return null;
        }
    }

    protected function getQuayLatestVersion(string $repository, string $currentTag): ?string
    {
        try {
            // Check if we've already fetched tags for this repository
            if (! isset($this->registryCache[$repository.'_tags'])) {
                $cleanRepo = str_replace('quay.io/', '', $repository);

                $url = "https://quay.io/api/v1/repository/{$cleanRepo}/tag/";

                $response = Http::timeout(10)->get($url, ['limit' => 100]);

                if (! $response->successful()) {
                    return null;
                }

                $data = $response->json();
                $tags = array_map(fn ($tag) => ['name' => $tag['name']], $data['tags'] ?? []);

                // Cache the tags for this repository
                $this->registryCache[$repository.'_tags'] = $tags;
            } else {
                $this->line("    [cached] Using cached tags for {$repository}");
                $tags = $this->registryCache[$repository.'_tags'];
            }

            return $this->findBestTag($tags, $currentTag, $repository);

        } catch (\Throwable $e) {
            $this->warn("  Quay API error for {$repository}: {$e->getMessage()}");

            return null;
        }
    }

    protected function getCodebergLatestVersion(string $repository, string $currentTag): ?string
    {
        try {
            // Check if we've already fetched tags for this repository
            if (! isset($this->registryCache[$repository.'_tags'])) {
                // Codeberg uses Forgejo/Gitea, which has a container registry API
                $cleanRepo = str_replace('codeberg.org/', '', $repository);
                $parts = explode('/', $cleanRepo);

                if (count($parts) < 2) {
                    return null;
                }

                $owner = $parts[0];
                $package = $parts[1];

                // Codeberg API endpoint for packages
                $url = "https://codeberg.org/api/packages/{$owner}/container/{$package}";

                $response = Http::timeout(10)->get($url);

                if (! $response->successful()) {
                    return null;
                }

                $data = $response->json();
                $tags = [];

                if (isset($data['versions'])) {
                    foreach ($data['versions'] as $version) {
                        if (isset($version['name'])) {
                            $tags[] = ['name' => $version['name']];
                        }
                    }
                }

                // Cache the tags for this repository
                $this->registryCache[$repository.'_tags'] = $tags;
            } else {
                $this->line("    [cached] Using cached tags for {$repository}");
                $tags = $this->registryCache[$repository.'_tags'];
            }

            return $this->findBestTag($tags, $currentTag, $repository);

        } catch (\Throwable $e) {
            $this->warn("  Codeberg API error for {$repository}: {$e->getMessage()}");

            return null;
        }
    }

    protected function findBestTag(array $tags, string $currentTag, string $repository): ?string
    {
        if (empty($tags)) {
            return null;
        }

        // If current tag is 'latest', find what version it actually points to
        if ($currentTag === 'latest') {
            // First, try to find the digest for 'latest' tag
            $latestDigest = $this->findLatestTagDigest($tags, 'latest');

            if ($latestDigest) {
                // Find all semantic version tags that share the same digest
                $versionTags = $this->findVersionTagsForDigest($tags, $latestDigest);

                if (! empty($versionTags)) {
                    // Prefer shorter version tags (1.8 over 1.8.1)
                    $bestVersion = $this->preferShorterVersion($versionTags);
                    $this->info("    ✓ Found 'latest' points to: {$bestVersion}");

                    return $repository.':'.$bestVersion;
                }
            }

            // Fallback: get the latest semantic version available (prefer shorter)
            $semverTags = $this->filterSemanticVersionTags($tags);
            if (! empty($semverTags)) {
                $bestVersion = $this->preferShorterVersion($semverTags);

                return $repository.':'.$bestVersion;
            }

            // If no semantic versions found, keep 'latest'
            return null;
        }

        // Check for major version updates for reporting
        $this->checkForMajorVersionUpdate($tags, $currentTag, $repository);

        // If current tag is a major version (e.g., "8", "5", "16")
        if (preg_match('/^\d+$/', $currentTag)) {
            $majorVersion = (int) $currentTag;
            $matchingTags = array_filter($tags, function ($tag) use ($majorVersion) {
                $name = $tag['name'];

                // Match tags that start with the major version
                return preg_match("/^{$majorVersion}(\.\d+)?(\.\d+)?$/", $name);
            });

            if (! empty($matchingTags)) {
                $versions = array_column($matchingTags, 'name');
                $bestVersion = $this->preferShorterVersion($versions);
                if ($bestVersion !== $currentTag) {
                    return $repository.':'.$bestVersion;
                }
            }
        }

        // If current tag is date-based version (e.g., "2025.06.02-sha-xxx")
        if (preg_match('/^\d{4}\.\d{2}\.\d{2}/', $currentTag)) {
            // Get all date-based tags
            $dateTags = array_filter($tags, function ($tag) {
                return preg_match('/^\d{4}\.\d{2}\.\d{2}/', $tag['name']);
            });

            if (! empty($dateTags)) {
                $versions = array_column($dateTags, 'name');
                $sorted = $this->sortSemanticVersions($versions);
                $latestDate = $sorted[0];

                // Compare dates
                if ($latestDate !== $currentTag) {
                    return $repository.':'.$latestDate;
                }
            }

            return null;
        }

        // If current tag is semantic version (e.g., "1.7.4", "8.0")
        if (preg_match('/^\d+\.\d+(\.\d+)?$/', $currentTag)) {
            $parts = explode('.', $currentTag);
            $majorMinor = $parts[0].'.'.$parts[1];

            $matchingTags = array_filter($tags, function ($tag) use ($majorMinor) {
                $name = $tag['name'];

                return str_starts_with($name, $majorMinor);
            });

            if (! empty($matchingTags)) {
                $versions = array_column($matchingTags, 'name');
                $bestVersion = $this->preferShorterVersion($versions);
                if (version_compare($bestVersion, $currentTag, '>') || version_compare($bestVersion, $currentTag, '=')) {
                    // Only update if it's newer or if we can simplify (1.8.1 -> 1.8)
                    if ($bestVersion !== $currentTag) {
                        return $repository.':'.$bestVersion;
                    }
                }
            }
        }

        // If current tag is a named version (e.g., "stable")
        if (in_array($currentTag, ['stable', 'lts', 'edge'])) {
            // Check if the same tag exists in the list (it's up to date)
            $exists = array_filter($tags, fn ($tag) => $tag['name'] === $currentTag);
            if (! empty($exists)) {
                return null; // Tag exists and is current
            }
        }

        return null;
    }

    protected function filterSemanticVersionTags(array $tags): array
    {
        $semverTags = array_filter($tags, function ($tag) {
            $name = $tag['name'];

            // Accept semantic versions (1.2.3, v1.2.3)
            if (preg_match('/^v?\d+\.\d+(\.\d+)?(\.\d+)?$/', $name)) {
                // Exclude versions with suffixes like -rc, -beta, -alpha
                if (preg_match('/-(rc|beta|alpha|dev|test|pre|snapshot)/i', $name)) {
                    return false;
                }

                return true;
            }

            // Accept date-based versions (2025.06.02, 2025.10.0, 2025.06.02-sha-xxx, RELEASE.2025-10-15T17-29-55Z)
            if (preg_match('/^\d{4}\.\d{2}\.(\d{2}|\d)/', $name) || preg_match('/^RELEASE\.\d{4}-\d{2}-\d{2}/', $name)) {
                return true;
            }

            return false;
        });

        return $this->sortSemanticVersions(array_column($semverTags, 'name'));
    }

    protected function sortSemanticVersions(array $versions): array
    {
        usort($versions, function ($a, $b) {
            // Check if these are date-based versions (YYYY.MM.DD or YYYY.MM.D format)
            $isDateA = preg_match('/^(\d{4})\.(\d{2})\.(\d{1,2})/', $a, $matchesA);
            $isDateB = preg_match('/^(\d{4})\.(\d{2})\.(\d{1,2})/', $b, $matchesB);

            if ($isDateA && $isDateB) {
                // Both are date-based (YYYY.MM.DD), compare as dates
                $dateA = $matchesA[1].$matchesA[2].str_pad($matchesA[3], 2, '0', STR_PAD_LEFT); // YYYYMMDD
                $dateB = $matchesB[1].$matchesB[2].str_pad($matchesB[3], 2, '0', STR_PAD_LEFT); // YYYYMMDD

                return strcmp($dateB, $dateA); // Descending order (newest first)
            }

            // Check if these are RELEASE date versions (RELEASE.YYYY-MM-DDTHH-MM-SSZ)
            $isReleaseA = preg_match('/^RELEASE\.(\d{4})-(\d{2})-(\d{2})T(\d{2})-(\d{2})-(\d{2})Z/', $a, $matchesA);
            $isReleaseB = preg_match('/^RELEASE\.(\d{4})-(\d{2})-(\d{2})T(\d{2})-(\d{2})-(\d{2})Z/', $b, $matchesB);

            if ($isReleaseA && $isReleaseB) {
                // Both are RELEASE format, compare as datetime
                $dateTimeA = $matchesA[1].$matchesA[2].$matchesA[3].$matchesA[4].$matchesA[5].$matchesA[6]; // YYYYMMDDHHMMSS
                $dateTimeB = $matchesB[1].$matchesB[2].$matchesB[3].$matchesB[4].$matchesB[5].$matchesB[6]; // YYYYMMDDHHMMSS

                return strcmp($dateTimeB, $dateTimeA); // Descending order (newest first)
            }

            // Strip 'v' prefix for version comparison
            $cleanA = ltrim($a, 'v');
            $cleanB = ltrim($b, 'v');

            // Fall back to semantic version comparison
            return version_compare($cleanB, $cleanA); // Descending order
        });

        return $versions;
    }

    protected function preferShorterVersion(array $versions): string
    {
        if (empty($versions)) {
            return '';
        }

        // Sort by version (highest first)
        $sorted = $this->sortSemanticVersions($versions);
        $highest = $sorted[0];

        // Parse the highest version
        $parts = explode('.', $highest);

        // Look for shorter versions that match
        // Priority: major (8) > major.minor (8.0) > major.minor.patch (8.0.39)

        // Try to find just major.minor (e.g., 1.8 instead of 1.8.1)
        if (count($parts) === 3) {
            $majorMinor = $parts[0].'.'.$parts[1];
            if (in_array($majorMinor, $versions)) {
                return $majorMinor;
            }
        }

        // Try to find just major (e.g., 8 instead of 8.0.39)
        if (count($parts) >= 2) {
            $major = $parts[0];
            if (in_array($major, $versions)) {
                return $major;
            }
        }

        // Return the highest version we found
        return $highest;
    }

    protected function updateYamlFile(string $filePath, string $originalContent, array $updatedYaml): void
    {
        // Preserve comments and formatting by updating the YAML content
        $lines = explode("\n", $originalContent);
        $updatedLines = [];
        $inServices = false;
        $currentService = null;

        foreach ($lines as $line) {
            // Detect if we're in the services section
            if (preg_match('/^services:/', $line)) {
                $inServices = true;
                $updatedLines[] = $line;

                continue;
            }

            // Detect service name (allow hyphens and underscores)
            if ($inServices && preg_match('/^  ([\w-]+):/', $line, $matches)) {
                $currentService = $matches[1];
                $updatedLines[] = $line;

                continue;
            }

            // Update image line
            if ($currentService && preg_match('/^(\s+)image:\s*(.+)$/', $line, $matches)) {
                $indent = $matches[1];
                $newImage = $updatedYaml['services'][$currentService]['image'] ?? $matches[2];
                $updatedLines[] = "{$indent}image: {$newImage}";

                continue;
            }

            // If we hit a non-indented line, we're out of services
            if ($inServices && preg_match('/^\S/', $line) && ! preg_match('/^services:/', $line)) {
                $inServices = false;
                $currentService = null;
            }

            $updatedLines[] = $line;
        }

        file_put_contents($filePath, implode("\n", $updatedLines));
    }

    protected function checkForMajorVersionUpdate(array $tags, string $currentTag, string $repository): void
    {
        // Only check semantic versions
        if (! preg_match('/^v?(\d+)\./', $currentTag, $currentMatches)) {
            return;
        }

        $currentMajor = (int) $currentMatches[1];

        // Get all semantic version tags
        $semverTags = $this->filterSemanticVersionTags($tags);

        // Find the highest major version available
        $highestMajor = $currentMajor;
        foreach ($semverTags as $version) {
            if (preg_match('/^v?(\d+)\./', $version, $matches)) {
                $major = (int) $matches[1];
                if ($major > $highestMajor) {
                    $highestMajor = $major;
                }
            }
        }

        // If there's a higher major version available, record it
        if ($highestMajor > $currentMajor) {
            $this->majorVersionUpdates[] = [
                'repository' => $repository,
                'current' => $currentTag,
                'current_major' => $currentMajor,
                'available_major' => $highestMajor,
                'registry_url' => $this->getRegistryUrl($repository.':'.$currentTag),
            ];
        }
    }

    protected function displayStats(): void
    {
        $this->info('Summary:');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Templates', $this->stats['total']],
                ['Updated', $this->stats['updated']],
                ['Skipped (up to date)', $this->stats['skipped']],
                ['Failed', $this->stats['failed']],
            ]
        );

        // Display major version updates if any
        if (! empty($this->majorVersionUpdates)) {
            $this->newLine();
            $this->warn('⚠ Services with available MAJOR version updates:');
            $this->newLine();

            $tableData = [];
            foreach ($this->majorVersionUpdates as $update) {
                $tableData[] = [
                    $update['repository'],
                    "v{$update['current_major']}.x",
                    "v{$update['available_major']}.x",
                    $update['registry_url'],
                ];
            }

            $this->table(
                ['Repository', 'Current', 'Available', 'Registry URL'],
                $tableData
            );

            $this->newLine();
            $this->comment('💡 Major version updates may include breaking changes. Review before upgrading.');
        }
    }
}
