<?php

namespace App\Services\ImageScan;

/**
 * Cell values for exported tables: Markdown cells that cannot break the table, and CSV cells that a spreadsheet does
 * not run as a formula.
 */
final class ExportCells
{
    /** A spreadsheet runs a cell that starts with one of these characters as a formula. */
    private const FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    public static function markdown(string $value): string
    {
        return str_replace(['\\', '|', "\r", "\n"], ['\\\\', '\\|', ' ', ' '], trim($value));
    }

    public static function csv(string|int|float|bool|null $value): string
    {
        $value = is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value;

        return $value !== '' && in_array($value[0], self::FORMULA_PREFIXES, true) ? "'".$value : $value;
    }

    /**
     * @param  list<string>  $header
     * @param  list<list<string|int|float|bool|null>>  $rows
     */
    public static function csvDocument(array $header, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $header, escape: '');
        foreach ($rows as $row) {
            fputcsv($handle, array_map(self::csv(...), $row), escape: '');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * @param  list<string>  $header
     * @param  list<list<string>>  $rows  Cells that are Markdown already (escape text with markdown()).
     */
    public static function markdownTable(string $title, array $header, array $rows): string
    {
        $lines = [
            '**'.self::markdown($title).'**',
            '',
            '| '.implode(' | ', $header).' |',
            '|'.str_repeat(' --- |', count($header)),
        ];
        foreach ($rows as $row) {
            $lines[] = '| '.implode(' | ', $row).' |';
        }

        return implode("\n", $lines)."\n";
    }

    public static function markdownLink(string $text, ?string $url): string
    {
        return $url === null || $url === '' ? self::markdown($text) : '['.str_replace(['[', ']'], ['\\[', '\\]'], self::markdown($text)).']('.str_replace([' ', ')'], ['%20', '%29'], $url).')';
    }
}
