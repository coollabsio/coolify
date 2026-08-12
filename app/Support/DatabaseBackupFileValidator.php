<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

class DatabaseBackupFileValidator
{
    public const ALLOWED_EXTENSIONS = [
        'sql',
        'sql.gz',
        'gz',
        'zip',
        'tar',
        'tar.gz',
        'tgz',
        'dump',
        'bak',
        'bson',
        'bson.gz',
        'archive',
        'archive.gz',
        'bz2',
        'xz',
        'dmp',
    ];

    private const DANGEROUS_EXTENSIONS = [
        'asp',
        'aspx',
        'bat',
        'bash',
        'cgi',
        'cmd',
        'com',
        'exe',
        'htm',
        'html',
        'jar',
        'js',
        'jsp',
        'php',
        'php3',
        'php4',
        'php5',
        'phtml',
        'pl',
        'ps1',
        'py',
        'rb',
        'sh',
    ];

    public static function hasAllowedExtension(string $name): bool
    {
        return self::extensionFor($name) !== null;
    }

    public static function isUploadAllowed(UploadedFile $file, int $maxBytes): bool
    {
        $originalName = $file->getClientOriginalName();
        $size = $file->getSize();

        if ($size === false || $size > $maxBytes) {
            return false;
        }

        $extension = self::extensionFor($originalName);
        if ($extension === null) {
            return false;
        }

        return self::contentMatchesExtension($file->getPathname(), $extension);
    }

    /**
     * Scan a stored backup file (decompressing gzip on the fly) for PostgreSQL
     * restore directives that lead to OS command execution.
     */
    public static function fileContainsPostgresqlProgramExecution(string $path): bool
    {
        $contents = self::readPossiblyGzippedText($path);

        if ($contents === null) {
            return false;
        }

        return self::containsPostgresqlProgramExecution($contents);
    }

    public static function containsPostgresqlProgramExecution(string $sql): bool
    {
        $withoutComments = self::stripSqlComments($sql);

        if (preg_match('/^\s*\\\\(?:!|copy\b.*\bprogram\b)/mi', $withoutComments) === 1) {
            return true;
        }

        return preg_match('/\bcopy\b[\s\S]{0,2000}\b(?:from|to)\s+program\b/i', $withoutComments) === 1;
    }

    private static function extensionFor(string $name): ?string
    {
        $lower = strtolower($name);
        $suffixes = array_map(fn (string $ext) => '.'.$ext, self::ALLOWED_EXTENSIONS);
        usort($suffixes, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        foreach ($suffixes as $suffix) {
            if (! str_ends_with($lower, $suffix)) {
                continue;
            }

            $stem = substr($lower, 0, -strlen($suffix));
            if ($stem === '' || str_ends_with($stem, '.')) {
                return null;
            }

            $parts = array_filter(explode('.', $stem));
            if (array_intersect($parts, self::DANGEROUS_EXTENSIONS) !== []) {
                return null;
            }

            return ltrim($suffix, '.');
        }

        return null;
    }

    private static function contentMatchesExtension(string $path, string $extension): bool
    {
        $sample = (string) file_get_contents($path, false, null, 0, 4096);

        return match ($extension) {
            'sql' => self::looksLikeText($sample) && ! self::containsPostgresqlProgramExecution($sample),
            'sql.gz', 'gz', 'tar.gz', 'tgz', 'bson.gz', 'archive.gz' => str_starts_with($sample, "\x1f\x8b"),
            'zip' => str_starts_with($sample, "PK\x03\x04") || str_starts_with($sample, "PK\x05\x06") || str_starts_with($sample, "PK\x07\x08"),
            'tar' => substr($sample, 257, 5) === 'ustar',
            'bz2' => str_starts_with($sample, 'BZh'),
            'xz' => str_starts_with($sample, "\xfd7zXZ\x00"),
            'dump', 'bak', 'archive', 'dmp' => str_starts_with($sample, 'PGDMP')
                || (self::looksLikeText($sample) && ! self::containsPostgresqlProgramExecution($sample)),
            'bson' => self::looksLikeBson($path, $sample),
            default => false,
        };
    }

    private static function readPossiblyGzippedText(string $path): ?string
    {
        // Cap the scan so a huge legitimate dump cannot exhaust memory; the
        // remote pre-restore scanner inspects the full file as a second layer.
        $maxBytes = 50 * 1024 * 1024;

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        $magic = (string) fread($handle, 2);
        fclose($handle);

        if ($magic === "\x1f\x8b") {
            $gz = @gzopen($path, 'rb');
            if ($gz === false) {
                return null;
            }

            $data = '';
            while (! gzeof($gz) && strlen($data) < $maxBytes) {
                $chunk = gzread($gz, 1024 * 1024);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $data .= $chunk;
            }
            gzclose($gz);

            return $data;
        }

        return (string) file_get_contents($path, false, null, 0, $maxBytes);
    }

    private static function looksLikeText(string $sample): bool
    {
        if ($sample === '' || str_contains($sample, "\0")) {
            return false;
        }

        return mb_check_encoding($sample, 'UTF-8') || mb_check_encoding($sample, 'ASCII');
    }

    private static function looksLikeBson(string $path, string $sample): bool
    {
        if (strlen($sample) < 5) {
            return false;
        }

        $documentLength = unpack('V', substr($sample, 0, 4))[1] ?? 0;
        $fileSize = filesize($path) ?: 0;

        return $documentLength >= 5 && $documentLength <= $fileSize;
    }

    private static function stripSqlComments(string $sql): string
    {
        $sql = preg_replace('/\/\*[\s\S]*?\*\//', ' ', $sql) ?? $sql;

        return preg_replace('/--[^\r\n]*/', ' ', $sql) ?? $sql;
    }
}
