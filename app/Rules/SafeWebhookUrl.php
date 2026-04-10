<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Log;

class SafeWebhookUrl implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * Validates that a webhook URL is safe for server-side requests.
     * Blocks loopback addresses, cloud metadata endpoints (link-local),
     * and dangerous hostnames while allowing private network IPs
     * for self-hosted deployments.
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

        // Block well-known dangerous hostnames
        $blockedHosts = ['localhost', '0.0.0.0', '::1'];
        if (in_array($host, $blockedHosts) || str_ends_with($host, '.internal')) {
            Log::warning('Webhook URL points to blocked host', [
                'attribute' => $attribute,
                'host' => $host,
                'ip' => request()->ip(),
                'user_id' => auth()->id(),
            ]);
            $fail('The :attribute must not point to localhost or internal hosts.');

            return;
        }

        // Block loopback (127.0.0.0/8) and link-local/metadata (169.254.0.0/16) when IP is provided directly
        if (filter_var($host, FILTER_VALIDATE_IP) && ($this->isLoopback($host) || $this->isLinkLocal($host))) {
            Log::warning('Webhook URL points to blocked IP range', [
                'attribute' => $attribute,
                'host' => $host,
                'ip' => request()->ip(),
                'user_id' => auth()->id(),
            ]);
            $fail('The :attribute must not point to loopback or link-local addresses.');

            return;
        }
    }

    private function isLoopback(string $ip): bool
    {
        // 127.0.0.0/8, 0.0.0.0
        if ($ip === '0.0.0.0' || str_starts_with($ip, '127.')) {
            return true;
        }

        // IPv6 loopback
        $normalized = @inet_pton($ip);

        return $normalized !== false && $normalized === inet_pton('::1');
    }

    private function isLinkLocal(string $ip): bool
    {
        // 169.254.0.0/16 — covers cloud metadata at 169.254.169.254
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        $long = ip2long($ip);

        return $long !== false && ($long >> 16) === (ip2long('169.254.0.0') >> 16);
    }
}
