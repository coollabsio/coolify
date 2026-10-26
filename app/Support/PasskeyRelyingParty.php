<?php

namespace App\Support;

use Illuminate\Http\Request;

class PasskeyRelyingParty
{
    /**
     * @return array{id: string, origins: list<string>}|null
     */
    public static function resolve(Request $request): ?array
    {
        $requestHost = $request->getHost();

        foreach ([instanceSettings()->fqdn, config('app.url')] as $url) {
            $parts = parse_url((string) $url);
            $host = strtolower($parts['host'] ?? '');

            if (strtolower($parts['scheme'] ?? '') !== 'https' || $host !== $requestHost) {
                continue;
            }
            if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
                continue;
            }

            $origins = ['https://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '')];

            // The fqdn setting stores no port, so a custom port can only come from the request.
            if (! isset($parts['port']) && ! in_array($request->getPort(), [80, 443])) {
                $origins[] = 'https://'.$host.':'.$request->getPort();
            }

            return ['id' => $host, 'origins' => $origins];
        }

        if ($requestHost === 'localhost') {
            return ['id' => 'localhost', 'origins' => [$request->getSchemeAndHttpHost()]];
        }

        return null;
    }

    public static function isAvailable(?Request $request = null): bool
    {
        return (bool) instanceSettings()->is_passkey_enabled
            && self::resolve($request ?? request()) !== null;
    }
}
