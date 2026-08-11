<?php

namespace App\Support;

class DomainUrlParts
{
    public static function compose(string $scheme, string $host, string $port = '', string $path = ''): string
    {
        $scheme = strtolower(trim($scheme)) === 'http' ? 'http' : 'https';
        $host = trim($host);
        $port = trim($port);
        $path = trim($path);

        if ($path !== '' && ! str_starts_with($path, '/') && ! str_starts_with($path, '?') && ! str_starts_with($path, '#')) {
            $path = '/'.$path;
        }

        return $scheme.'://'.$host.($port !== '' ? ':'.$port : '').$path;
    }

    /**
     * @return array{scheme: string, host: string, port: string, path: string}
     */
    public static function split(?string $url): array
    {
        $parts = filled($url) ? parse_url($url) : false;
        if (! is_array($parts) || blank($parts['host'] ?? null)) {
            return self::empty();
        }

        $path = $parts['path'] ?? '';
        if (isset($parts['query'])) {
            $path .= '?'.$parts['query'];
        }
        if (isset($parts['fragment'])) {
            $path .= '#'.$parts['fragment'];
        }

        return [
            'scheme' => in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
                ? strtolower($parts['scheme'])
                : 'https',
            'host' => (string) $parts['host'],
            'port' => isset($parts['port']) ? (string) $parts['port'] : '',
            'path' => $path,
        ];
    }

    /**
     * @return array{scheme: string, host: string, port: string, path: string}
     */
    public static function empty(): array
    {
        return ['scheme' => 'https', 'host' => '', 'port' => '', 'path' => ''];
    }
}
