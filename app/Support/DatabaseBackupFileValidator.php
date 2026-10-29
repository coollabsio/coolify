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
        'db',
        'db.gz',
        'sqlite',
        'sqlite.gz',
        'sqlite3',
        'sqlite3.gz',
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
        if (str_starts_with($sql, 'PGDMP')) {
            return false;
        }

        $sql = self::withoutCopyData($sql);

        // Token separator PostgreSQL treats as whitespace: real whitespace, a block comment
        // (the lazy match can extend to the outer end of a nested comment), or a line
        // comment. Comment forms also match inside string literals, which can only block
        // more input, never less.
        $sep = '(?:\s|/\*.*?\*/|--[^\r\n]*[\r\n])';

        // psql meta-commands that can run a shell command, anywhere on a line. Same rules as
        // the restore scan in DatabaseImportCommandBuilder::buildPostgresRestoreScanScript().
        // A regex error (for example the backtrack limit) returns false and blocks.
        if (preg_match('~(?:^|[^\\\\])(?:\\\\\\\\)*\\\\(?:!|(?:o|out|gx?|w|write)[^\S\n][^\\\\\n]*\||copy[^\S\n].*program|(?:setenv|e|edit|ef|ev|i|ir|include|include_relative)(?:\s|$)|(?:[^nbv\s\\\\]|[nbv][^\s\\\\])[^\s\\\\]*[^\S\n][^\\\\\n]*`)~mi', $sql) !== 0) {
            return true;
        }

        // A TO/FROM PROGRAM token pair anywhere (dynamic SQL, DO blocks, function bodies),
        // a server-side large-object import or export, or ALTER SYSTEM. Comments are no
        // longer stripped first, because that cut inside string literals; a commented-out
        // PROGRAM statement is now blocked too, which is the safer default.
        return preg_match('~(?:from|to)'.$sep.'+program|lo_(?:import|export)'.$sep.'*\(|alter'.$sep.'+system'.$sep.'+(?:set|reset)~is', $sql) !== 0;
    }

    /**
     * Removes the table data of COPY ... FROM stdin blocks: rows are data, not SQL, and ordinary
     * text such as "how to program" matched the patterns. Same rule as the restore scan in
     * DatabaseImportCommandBuilder: a block starts at the line "COPY ... FROM stdin;" as pg_dump
     * writes it and ends at the line "\.".
     */
    private static function withoutCopyData(string $sql): string
    {
        $kept = '';
        $offset = 0;

        while (preg_match('/^COPY .* FROM stdin;$/m', $sql, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $dataStart = $match[0][1] + strlen($match[0][0]);
            $kept .= substr($sql, $offset, $dataStart - $offset);
            $end = preg_match('/^\\\\\\.$/m', $sql, $terminator, PREG_OFFSET_CAPTURE, $dataStart) === 1
                ? $terminator[0][1] + 2
                : strlen($sql);
            $offset = $end;
        }

        return $kept.substr($sql, $offset);
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
            'sql' => self::looksLikeText($sample),
            'sql.gz', 'gz', 'tar.gz', 'tgz', 'bson.gz', 'archive.gz', 'db.gz', 'sqlite.gz', 'sqlite3.gz' => str_starts_with($sample, "\x1f\x8b"),
            'zip' => str_starts_with($sample, "PK\x03\x04") || str_starts_with($sample, "PK\x05\x06") || str_starts_with($sample, "PK\x07\x08"),
            'tar' => substr($sample, 257, 5) === 'ustar',
            'bz2' => str_starts_with($sample, 'BZh'),
            'xz' => str_starts_with($sample, "\xfd7zXZ\x00"),
            'dump', 'bak', 'archive', 'dmp' => str_starts_with($sample, 'PGDMP')
                || self::looksLikeText($sample),
            'bson' => self::looksLikeBson($path, $sample),
            'db', 'sqlite', 'sqlite3' => str_starts_with($sample, "SQLite format 3\0"),
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
}
