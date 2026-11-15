<?php

namespace App\Services\NodeStack;

use App\Models\GithubApp;
use App\Models\PrivateKey;
use RuntimeException;

/**
 * The Git source of a stack from a Compose application (step 2 of decision 0023): the repository,
 * its branch and credential, where the Compose file is, and the commit that it was read at.
 */
final readonly class NodeStackGitSource
{
    public const DEFAULT_COMPOSE_LOCATION = '/docker-compose.yaml';

    public function __construct(
        public string $repository,
        public string $branch,
        public string $baseDirectory,
        public string $composeLocation,
        public string $commitSha,
        public ?GithubApp $githubApp = null,
        public ?PrivateKey $privateKey = null,
    ) {}

    /** The directory of the Compose file in the repository, without leading or trailing `/`. */
    public function composeDirectory(): string
    {
        return self::composeDirectoryOf($this->baseDirectory, $this->composeLocation);
    }

    /** The directory of the Compose file at `<base directory>/<compose location>` in the repository. */
    public static function composeDirectoryOf(?string $baseDirectory, ?string $composeLocation): string
    {
        $directory = dirname(self::repositoryPath($baseDirectory, $composeLocation ?? self::DEFAULT_COMPOSE_LOCATION));

        return $directory === '.' ? '' : $directory;
    }

    /**
     * Joins paths of the repository to one path without leading or trailing `/`: `/apps/` and
     * `/docker-compose.yaml` become `apps/docker-compose.yaml`. The root is ``.
     *
     * @throws RuntimeException When a part has `..` or a null byte, so the path could leave the repository.
     */
    public static function repositoryPath(?string ...$parts): string
    {
        $segments = [];
        foreach ($parts as $part) {
            $part = (string) $part;
            if (str_contains($part, "\0")) {
                throw new RuntimeException('A repository path cannot contain a null byte.');
            }
            foreach (explode('/', str_replace('\\', '/', $part)) as $segment) {
                if ($segment === '' || $segment === '.') {
                    continue;
                }
                if ($segment === '..') {
                    throw new RuntimeException('A repository path cannot contain "..".');
                }
                $segments[] = $segment;
            }
        }

        return implode('/', $segments);
    }
}
