<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Log;

class ValidGithubUrl implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * Validates that a GitHub API or HTML URL is safe from SSRF attacks:
     * - Must use HTTPS scheme
     * - Must not point to private/internal IP ranges
     * - Must not point to cloud metadata endpoints
     * - Must not point to localhost or loopback addresses
     * - Must have a valid public hostname
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($value)) {
            return;
        }

        $value = trim($value);

        $parsed = parse_url($value);

        if ($parsed === false || empty($parsed['scheme']) || empty($parsed['host'])) {
            $fail('The :attribute is not a valid URL.');

            return;
        }

        // Must use HTTPS
        if (strtolower($parsed['scheme']) !== 'https') {
            $fail('The :attribute must use HTTPS.');

            return;
        }

        $host = strtolower($parsed['host']);

        // Block localhost and loopback addresses
        $blockedHosts = ['localhost', '127.0.0.1', '0.0.0.0', '::1', '[::1]'];
        if (in_array($host, $blockedHosts) || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            $this->logAttempt($attribute, $value, 'internal host');
            $fail('The :attribute must not point to internal hosts.');

            return;
        }

        // If the host is an IP address, validate it's not in a private/reserved range
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $this->logAttempt($attribute, $value, 'private/reserved IP');
                $fail('The :attribute must not point to private or reserved IP addresses.');

                return;
            }

            // Block cloud metadata IP (169.254.169.254)
            if ($host === '169.254.169.254') {
                $this->logAttempt($attribute, $value, 'cloud metadata endpoint');
                $fail('The :attribute must not point to cloud metadata endpoints.');

                return;
            }
        }

        // Block cloud metadata hostnames
        $metadataHosts = [
            'metadata.google.internal',
            'metadata.google.com',
            'instance-data',
        ];
        foreach ($metadataHosts as $metadataHost) {
            if ($host === $metadataHost) {
                $this->logAttempt($attribute, $value, 'cloud metadata hostname');
                $fail('The :attribute must not point to cloud metadata endpoints.');

                return;
            }
        }

        // Ensure no userinfo component (can be used for URL confusion attacks)
        if (! empty($parsed['user']) || ! empty($parsed['pass'])) {
            $this->logAttempt($attribute, $value, 'URL with credentials');
            $fail('The :attribute must not contain user credentials.');

            return;
        }
    }

    private function logAttempt(string $attribute, string $value, string $reason): void
    {
        try {
            $logData = [
                'attribute' => $attribute,
                'url' => $value,
                'reason' => $reason,
            ];

            if (function_exists('request') && app()->has('request')) {
                $logData['ip'] = request()->ip();
            }

            if (function_exists('auth') && app()->has('auth')) {
                $logData['user_id'] = auth()->id();
            }

            Log::warning('GitHub URL validation failed - potential SSRF attempt', $logData);
        } catch (\Throwable $e) {
            // Ignore errors when facades are not available (e.g., in unit tests)
        }
    }
}
