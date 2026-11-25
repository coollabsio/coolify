<?php

namespace App\Services\DeployTriggers\Resolution;

use Closure;

/**
 * The tree of one commit, with lazily loaded file contents.
 */
final class RepositorySnapshot
{
    public const MODE_SYMLINK = '120000';

    /** @var array<string, string|null> oid => content */
    private array $contents = [];

    /** @var array<string, true>|null */
    private ?array $directories = null;

    /**
     * @param  array<string, array{mode: string, oid: string}>  $entries  path => entry
     * @param  Closure(list<string>): array<string, string>  $loader  oids => contents
     */
    public function __construct(private readonly array $entries, private readonly Closure $loader) {}

    /**
     * @param  array<string, string>  $files  path => content
     * @param  array<string, string>  $modes  path => git mode
     */
    public static function fromFiles(array $files, array $modes = []): self
    {
        $entries = [];
        $contents = [];
        foreach ($files as $path => $content) {
            $oid = sha1($path."\0".$content);
            $entries[$path] = ['mode' => $modes[$path] ?? '100644', 'oid' => $oid];
            $contents[$oid] = $content;
        }

        return new self($entries, fn (array $oids): array => array_intersect_key($contents, array_flip($oids)));
    }

    public function has(string $path): bool
    {
        return isset($this->entries[$path]);
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return array_keys($this->entries);
    }

    public function mode(string $path): ?string
    {
        return $this->entries[$path]['mode'] ?? null;
    }

    public function oid(string $path): ?string
    {
        return $this->entries[$path]['oid'] ?? null;
    }

    public function read(string $path): ?string
    {
        $oid = $this->oid($path);
        if ($oid === null) {
            return null;
        }
        $this->prefetch([$path]);

        return $this->contents[$oid] ?? null;
    }

    /**
     * Loads the contents of several files in one call to the loader.
     *
     * @param  list<string>  $paths
     */
    public function prefetch(array $paths): void
    {
        $missing = [];
        foreach ($paths as $path) {
            $oid = $this->oid($path);
            if ($oid !== null && ! array_key_exists($oid, $this->contents)) {
                $missing[$oid] = true;
            }
        }
        if ($missing === []) {
            return;
        }

        $loaded = ($this->loader)(array_keys($missing));
        foreach (array_keys($missing) as $oid) {
            $this->contents[$oid] = $loaded[$oid] ?? null;
        }
    }

    /**
     * @return list<string>
     */
    public function filesNamed(string $basename): array
    {
        return array_values(array_filter($this->paths(), fn (string $path): bool => $path === $basename || str_ends_with($path, '/'.$basename)));
    }

    public function directoryExists(string $directory): bool
    {
        if ($this->directories === null) {
            $this->directories = [];
            foreach ($this->paths() as $path) {
                for ($dir = RepoPath::directory($path); $dir !== ''; $dir = RepoPath::directory($dir)) {
                    if (isset($this->directories[$dir])) {
                        break;
                    }
                    $this->directories[$dir] = true;
                }
            }
        }

        return $directory === '' || isset($this->directories[$directory]);
    }

    /**
     * @return list<string>
     */
    public function symlinks(): array
    {
        return array_keys(array_filter($this->entries, fn (array $entry): bool => $entry['mode'] === self::MODE_SYMLINK));
    }
}
