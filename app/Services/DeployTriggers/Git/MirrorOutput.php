<?php

namespace App\Services\DeployTriggers\Git;

use App\Services\DeployTriggers\AffectedRunnerException;
use App\Services\DeployTriggers\Contract\AffectedContractException;
use App\Services\DeployTriggers\Resolution\RepositorySnapshot;

/**
 * Parses the `@@TAG` lines of a mirror script.
 */
final class MirrorOutput
{
    /**
     * @param  list<array{0: string, 1: list<string>}>  $frames
     */
    private function __construct(private readonly array $frames) {}

    /**
     * @param  callable(string): string  $redact
     *
     * @throws AffectedRunnerException When the script reported an error.
     * @throws AffectedContractException When the output is cut off or malformed.
     */
    public static function parse(string $output, callable $redact): self
    {
        $frames = [];
        $ended = false;
        foreach (explode("\n", $output) as $line) {
            if (! str_starts_with($line, '@@')) {
                continue;
            }
            $fields = explode(' ', rtrim(substr($line, 2), "\r"));
            $tag = array_shift($fields);
            if ($tag === 'ERROR') {
                throw new AffectedRunnerException($redact(trim((string) base64_decode($fields[0] ?? '', true))));
            }
            if ($tag === 'END') {
                $ended = true;

                continue;
            }
            $frames[] = [$tag, $fields];
        }

        if (! $ended) {
            throw new AffectedContractException('The git mirror output is incomplete.');
        }

        return new self($frames);
    }

    /**
     * @return list<list<string>>
     */
    public function all(string $tag): array
    {
        return array_values(array_map(fn (array $frame): array => $frame[1], array_filter($this->frames, fn (array $frame): bool => $frame[0] === $tag)));
    }

    /**
     * @return list<string>|null
     */
    public function first(string $tag): ?array
    {
        return $this->all($tag)[0] ?? null;
    }

    /**
     * @throws AffectedContractException
     */
    public static function payload(string $field, int $maxBytes = 268435456): string
    {
        if ($field === '') {
            return '';
        }
        $compressed = base64_decode($field, true);
        $data = $compressed === false ? false : @gzdecode($compressed, $maxBytes);
        if ($data === false) {
            throw new AffectedContractException('The git mirror output has an invalid payload.');
        }

        return $data;
    }

    /**
     * @return list<string>
     */
    public static function nulSeparated(string $data): array
    {
        return array_values(array_filter(explode("\0", $data), fn (string $item): bool => $item !== ''));
    }

    /**
     * Parses `git diff-tree -r -z` raw output into the changed paths and the ones that are or were symlinks.
     *
     * @return array{files: list<string>, symlinks: list<string>}
     */
    public static function rawDiff(string $data): array
    {
        $files = [];
        $symlinks = [];
        $fields = explode("\0", $data);
        for ($i = 0; $i + 1 < count($fields); $i += 2) {
            $meta = explode(' ', ltrim($fields[$i], ':'));
            $path = $fields[$i + 1];
            if (count($meta) < 5 || $path === '') {
                continue;
            }
            $files[] = $path;
            if ($meta[0] === RepositorySnapshot::MODE_SYMLINK || $meta[1] === RepositorySnapshot::MODE_SYMLINK) {
                $symlinks[] = $path;
            }
        }

        return ['files' => $files, 'symlinks' => $symlinks];
    }

    /**
     * Parses `git ls-tree -r -z` output into path => mode and oid.
     *
     * @return array<string, array{mode: string, oid: string}>
     */
    public static function tree(string $data): array
    {
        $entries = [];
        foreach (self::nulSeparated($data) as $record) {
            $tab = strpos($record, "\t");
            if ($tab === false) {
                continue;
            }
            $meta = explode(' ', substr($record, 0, $tab));
            if (count($meta) === 3 && $meta[1] !== 'tree') {
                $entries[substr($record, $tab + 1)] = ['mode' => $meta[0], 'oid' => $meta[2]];
            }
        }

        return $entries;
    }

    /**
     * Parses `git cat-file --batch` output into oid => content.
     *
     * @return array<string, string>
     */
    public static function blobs(string $data): array
    {
        $blobs = [];
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $end = strpos($data, "\n", $offset);
            if ($end === false) {
                break;
            }
            $header = explode(' ', substr($data, $offset, $end - $offset));
            $offset = $end + 1;
            if (count($header) !== 3) {
                continue;
            }
            $size = (int) $header[2];
            if ($header[1] === 'blob') {
                $blobs[$header[0]] = substr($data, $offset, $size);
            }
            $offset += $size + 1;
        }

        return $blobs;
    }
}
