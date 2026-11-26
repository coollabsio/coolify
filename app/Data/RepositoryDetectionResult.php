<?php

namespace App\Data;

use App\Enums\BuildPackTypes;
use Spatie\LaravelData\Data;

class RepositoryDetectionResult extends Data
{
    /**
     * @param  array<int, string>  $dockerfiles  e.g. ['Dockerfile', 'apps/api/Dockerfile']
     * @param  array<int, string>  $dockerComposeFiles  e.g. ['docker-compose.yml']
     * @param  array<string, string|null>  $envFiles  e.g. ['.env.example' => 'KEY=val...', '.env.dist' => null]
     * @param  array<string, int|null>  $dockerfilePorts  e.g. ['Dockerfile' => 3000, 'apps/api/Dockerfile' => 8080]
     */
    public function __construct(
        public array $dockerfiles = [],
        public array $dockerComposeFiles = [],
        public array $envFiles = [],
        public array $dockerfilePorts = [],
    ) {}

    public static function none(): self
    {
        return new self;
    }

    /**
     * Build pack for the files in the base directory. Files in subdirectories are often examples,
     * tests, or other services, so they do not select a build pack.
     */
    public function getSuggestedBuildPack(): ?BuildPackTypes
    {
        if ($this->baseDirectoryFile($this->dockerComposeFiles)) {
            return BuildPackTypes::DOCKERCOMPOSE;
        }

        if ($this->baseDirectoryFile($this->dockerfiles)) {
            return BuildPackTypes::DOCKERFILE;
        }

        return null;
    }

    /**
     * The first file that is directly in the base directory, not in a subdirectory.
     *
     * @param  array<int, string>  $files
     */
    public function baseDirectoryFile(array $files): ?string
    {
        return collect($files)->first(fn (string $file): bool => ! str_contains($file, '/'));
    }

    public function hasDockerfile(): bool
    {
        return count($this->dockerfiles) > 0;
    }

    public function hasDockerCompose(): bool
    {
        return count($this->dockerComposeFiles) > 0;
    }

    public function hasEnvFiles(): bool
    {
        return count($this->envFiles) > 0;
    }
}
