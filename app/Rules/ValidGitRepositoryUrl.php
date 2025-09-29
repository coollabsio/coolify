<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Log;

class ValidGitRepositoryUrl implements ValidationRule
{
    protected bool $allowSSH;

    protected bool $allowIP;

    public function __construct(bool $allowSSH = true, bool $allowIP = false)
    {
        $this->allowSSH = $allowSSH;
        $this->allowIP = $allowIP;
    }

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($value)) {
            return;
        }

        // Check for dangerous shell metacharacters that could be used for command injection
        $dangerousChars = [
            ';', '|', '&', '$', '`', '(', ')', '{', '}',
            '[', ']', '<', '>', '\n', '\r', '\0', '"', "'",
            '\\', '!', '?', '*', '^', '%', '=', '+',
            '#', // Comment character that could hide commands
        ];

        foreach ($dangerousChars as $char) {
            if (str_contains($value, $char)) {
                Log::warning('Git repository URL validation failed - dangerous character', [
                    'url' => $value,
                    'character' => $char,
                    'ip' => request()->ip(),
                    'user_id' => auth()->id(),
                ]);
                $fail('The :attribute contains invalid characters.');

                return;
            }
        }

        // Check for command substitution patterns
        $dangerousPatterns = [
            '/\$\(.*\)/',  // Command substitution $(...)
            '/\${.*}/',    // Variable expansion ${...}
            '/;;/',        // Double semicolon
            '/&&/',        // Command chaining
            '/\|\|/',      // Command chaining
            '/>>/',        // Redirect append
            '/<</',        // Here document
            '/\\\n/',      // Line continuation
            '/\.\.[\/\\\\]/', // Directory traversal
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $value)) {
                Log::warning('Git repository URL validation failed - dangerous pattern', [
                    'url' => $value,
                    'pattern' => $pattern,
                    'ip' => request()->ip(),
                    'user_id' => auth()->id(),
                ]);
                $fail('The :attribute contains invalid patterns.');

                return;
            }
        }

        // Validate based on URL type
        if (str_starts_with($value, 'git@')) {
            if (! $this->allowSSH) {
                $fail('SSH URLs are not allowed.');

                return;
            }

            // Validate SSH URL format (git@host:user/repo.git)
            if (! preg_match('/^git@[a-zA-Z0-9\.\-]+:[a-zA-Z0-9\-_\/\.~]+$/', $value)) {
                $fail('The :attribute is not a valid SSH repository URL.');

                return;
            }
        } elseif (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            // Validate HTTP(S) URL
            if (! filter_var($value, FILTER_VALIDATE_URL)) {
                $fail('The :attribute is not a valid URL.');

                return;
            }

            $parsed = parse_url($value);

            // Check for IP addresses if not allowed
            if (! $this->allowIP && filter_var($parsed['host'] ?? '', FILTER_VALIDATE_IP)) {
                Log::warning('Git repository URL contains IP address', [
                    'url' => $value,
                    'ip' => request()->ip(),
                    'user_id' => auth()->id(),
                ]);
                $fail('The :attribute cannot use IP addresses.');

                return;
            }

            // Check for localhost/internal addresses
            $host = strtolower($parsed['host'] ?? '');
            $internalHosts = ['localhost', '127.0.0.1', '0.0.0.0', '::1'];
            if (in_array($host, $internalHosts) || str_ends_with($host, '.local')) {
                Log::warning('Git repository URL points to internal host', [
                    'url' => $value,
                    'host' => $host,
                    'ip' => request()->ip(),
                    'user_id' => auth()->id(),
                ]);
                $fail('The :attribute cannot point to internal hosts.');

                return;
            }

            // Ensure no query parameters or fragments
            if (! empty($parsed['query']) || ! empty($parsed['fragment'])) {
                $fail('The :attribute should not contain query parameters or fragments.');

                return;
            }

            // Validate path contains only safe characters
            $path = $parsed['path'] ?? '';
            if (! empty($path) && ! preg_match('/^[a-zA-Z0-9\-_\/\.@~]+$/', $path)) {
                $fail('The :attribute path contains invalid characters.');

                return;
            }
        } elseif (str_starts_with($value, 'git://')) {
            // Validate git:// protocol URL (supports both git://host/path and git://host:port/path with tilde)
            if (! preg_match('/^git:\/\/[a-zA-Z0-9\.\-]+(:[0-9]+)?[:\/][a-zA-Z0-9\-_\/\.~]+$/', $value)) {
                $fail('The :attribute is not a valid git:// URL.');

                return;
            }
        } else {
            $fail('The :attribute must start with https://, http://, git://, or git@.');

            return;
        }
    }
}
