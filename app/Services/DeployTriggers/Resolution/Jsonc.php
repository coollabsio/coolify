<?php

namespace App\Services\DeployTriggers\Resolution;

/**
 * Reads JSON with comments and trailing commas, as tsconfig.json allows.
 */
final class Jsonc
{
    /**
     * @return array<mixed>|null null when the content is not valid JSONC
     */
    public static function decode(string $content): ?array
    {
        $json = '';
        $length = strlen($content);
        for ($i = 0; $i < $length; $i++) {
            $char = $content[$i];
            if ($char === '"') {
                $end = $i + 1;
                while ($end < $length && $content[$end] !== '"') {
                    $end += $content[$end] === '\\' ? 2 : 1;
                }
                $json .= substr($content, $i, $end - $i + 1);
                $i = $end;
            } elseif ($char === '/' && ($content[$i + 1] ?? '') === '/') {
                $newline = strpos($content, "\n", $i);
                $i = $newline === false ? $length : $newline - 1;
            } elseif ($char === '/' && ($content[$i + 1] ?? '') === '*') {
                $close = strpos($content, '*/', $i + 2);
                $i = $close === false ? $length : $close + 1;
            } else {
                $json .= $char;
            }
        }
        $json = preg_replace('/,(\s*[}\]])/', '$1', $json) ?? $json;
        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }
}
