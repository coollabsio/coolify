<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Log;

class SafeExternalUrl implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * Validates that a URL points to an external, publicly-routable host.
     * Blocks private IP ranges, reserved ranges, localhost, and link-local
     * addresses to prevent Server-Side Request Forgery (SSRF).
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! filter_var($value, FILTER_VALIDATE_URL)) {
            $fail('The :attribute must be a valid URL.');

            return;
        }

        $scheme = strtolower(parse_url($value, PHP_URL_SCHEME) ?? '');
        if (! in_array($scheme, ['https', 'http'])) {
            $fail('The :attribute must use the http or https scheme.');

            return;
        }

        $host = parse_url($value, PHP_URL_HOST);
        if (! $host) {
            $fail('The :attribute must contain a valid host.');

            return;
        }

        $host = strtolower($host);

        // Block well-known internal hostnames
        $internalHosts = ['localhost', '0.0.0.0', '::1'];
        if (in_array($host, $internalHosts) || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            Log::warning('External URL points to internal host', [
                'attribute' => $attribute,
                'url' => $value,
                'host' => $host,
                'ip' => request()->ip(),
                'user_id' => auth()->id(),
            ]);
            $fail('The :attribute must not point to internal hosts.');

            return;
        }

        // Resolve hostname to IP and block private/reserved ranges
        $ip = gethostbyname($host);

        // gethostbyname returns the original hostname on failure (e.g. unresolvable)
        if ($ip === $host && ! filter_var($host, FILTER_VALIDATE_IP)) {
            $fail('The :attribute host could not be resolved.');

            return;
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            Log::warning('External URL resolves to private or reserved IP', [
                'attribute' => $attribute,
                'url' => $value,
                'host' => $host,
                'resolved_ip' => $ip,
                'ip' => request()->ip(),
                'user_id' => auth()->id(),
            ]);
            $fail('The :attribute must not point to a private or reserved IP address.');

            return;
        }
    }
}
