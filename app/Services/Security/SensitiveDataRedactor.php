<?php

namespace App\Services\Security;

use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Stringable;
use Throwable;
use UnitEnum;

final class SensitiveDataRedactor
{
    public const FAILURE_MESSAGE = '[LOG SUPPRESSED: REDACTION FAILED]';

    private const CHUNK_BYTES = 262144;

    private const MIN_KNOWN_SECRET_LENGTH = 8;

    private const EMAIL_PATTERN = '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}/';

    private bool $redactEmails = true;

    private const SENSITIVE_KEYS = ['password', 'passwd', 'pwd', 'secret', 'client_secret', 'api_key', 'apikey', 'access_key', 'private_key', 'token', 'access_token', 'refresh_token', 'auth_token', 'authorization', 'proxy_authorization', 'cookie', 'set_cookie', 'webhook_secret', 'signing_secret', 'credential', 'credentials', 'connection_string', 'database_url'];

    /**
     * Return a copy that keeps email addresses, for sinks such as the audit log that must record who acted.
     */
    public function withoutEmailRedaction(): self
    {
        $redactor = clone $this;
        $redactor->redactEmails = false;

        return $redactor;
    }

    /**
     * @param  array<array-key, mixed>  $knownSecrets  Exact values to replace when they are long enough to be safe to match, such as unlocked environment values.
     * @param  array<array-key, mixed>  $lockedSecrets  Exact values to always replace, whatever their length, such as locked or remote secrets.
     */
    public function redactText(string $text, array $knownSecrets = [], array $lockedSecrets = []): string
    {
        try {
            $text = $this->normalizeText($text);
            $text = $this->replaceKnownSecrets($text, $knownSecrets, $lockedSecrets);

            $redacted = '';
            foreach ($this->chunks($text) as $chunk) {
                $redacted .= $this->redactAssignments($this->redactPatterns($chunk));
            }

            return $redacted;
        } catch (Throwable) {
            return self::FAILURE_MESSAGE;
        }
    }

    /**
     * @param  array<array-key, mixed>  $knownSecrets
     */
    public function redactValue(mixed $value, array $knownSecrets = [], ?string $key = null): mixed
    {
        try {
            if ($key !== null && $this->isSensitiveKey($key)) {
                return REDACTED;
            }
            if (is_array($value)) {
                $redacted = [];
                foreach ($value as $itemKey => $itemValue) {
                    $redacted[$itemKey] = $this->redactValue($itemValue, $knownSecrets, is_string($itemKey) ? $itemKey : null);
                }

                return $redacted;
            }
            if (is_string($value)) {
                return $this->redactText($value, $knownSecrets);
            }
            if (! is_object($value) || $value instanceof UnitEnum || $value instanceof DateTimeInterface) {
                return $value;
            }
            if ($value instanceof Arrayable) {
                return $this->redactValue($value->toArray(), $knownSecrets);
            }
            if ($value instanceof JsonSerializable) {
                return $this->redactValue($value->jsonSerialize(), $knownSecrets);
            }
            if ($value instanceof Stringable) {
                return $this->redactText((string) $value, $knownSecrets);
            }

            return '[object '.$value::class.']';
        } catch (Throwable) {
            return self::FAILURE_MESSAGE;
        }
    }

    private function normalizeText(string $text): string
    {
        $text = sanitize_utf8_text($text);
        $text = preg_replace('/\x1B(?:[@-Z\\-_]|\[[0-?]*[ -\/]*[@-~])/', '', $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        if ($text === null) {
            throw new \RuntimeException('Log normalization failed.');
        }

        return $text;
    }

    /**
     * Split large text on line boundaries so the regular expressions stay inside PCRE limits.
     * A chunk never ends inside a private key block.
     *
     * @return iterable<int, string>
     */
    private function chunks(string $text): iterable
    {
        if (strlen($text) <= self::CHUNK_BYTES) {
            yield $text;

            return;
        }

        $chunk = '';
        $insidePrivateKey = false;
        foreach (preg_split('/(?<=\n)/', $text) ?: [$text] as $line) {
            $chunk .= $line;
            if (preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----/', $line) === 1) {
                $insidePrivateKey = true;
            }
            if (preg_match('/-----END [A-Z ]*PRIVATE KEY-----/', $line) === 1) {
                $insidePrivateKey = false;
            }
            if (! $insidePrivateKey && strlen($chunk) >= self::CHUNK_BYTES) {
                yield $chunk;
                $chunk = '';
            }
        }

        if ($chunk !== '') {
            yield $chunk;
        }
    }

    /**
     * @param  array<array-key, mixed>  $knownSecrets
     * @param  array<array-key, mixed>  $lockedSecrets
     */
    private function replaceKnownSecrets(string $text, array $knownSecrets, array $lockedSecrets): string
    {
        $variants = [];
        foreach ([[$knownSecrets, self::MIN_KNOWN_SECRET_LENGTH], [$lockedSecrets, 1]] as [$secrets, $minimumLength]) {
            foreach ($secrets as $secret) {
                if (! is_scalar($secret) || trim((string) $secret) === '' || strlen((string) $secret) < $minimumLength) {
                    continue;
                }
                $secret = (string) $secret;
                $variants[] = $secret;
                $variants[] = rawurlencode($secret);
                $json = json_encode($secret, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
                $variants[] = substr($json, 1, -1);
            }
        }
        $variants = array_values(array_unique(array_filter($variants)));
        usort($variants, fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        return str_replace($variants, REDACTED, $text);
    }

    private function redactAssignments(string $text): string
    {
        $assignmentKeys = array_diff(self::SENSITIVE_KEYS, ['authorization', 'proxy_authorization']);
        $keys = implode('|', array_map(fn (string $key): string => preg_quote($key, '/'), $assignmentKeys));
        $keys = '(?i:'.$keys.')|[A-Z][A-Z0-9_]*(?:SECRET|PASSWORD|TOKEN|PASSWD|API_KEY|PRIVATE_KEY)[A-Z0-9_]*';
        $value = '(?:"[^"\r\n]*"|\'[^\'\r\n]*\'|[^\s,;}\]]+)';
        $result = preg_replace('/(?<![\w-])(["\']?(?:'.$keys.')["\']?\s*[=:]\s*)'.$value.'/', '$1'.REDACTED, $text);
        if ($result === null) {
            throw new \RuntimeException('Log redaction failed.');
        }

        return $result;
    }

    private function redactPatterns(string $text): string
    {
        $patterns = [
            '/((?:Authorization|Proxy-Authorization)\s*:\s*)[^\r\n]+/i' => '$1'.REDACTED,
            '/x-access-token:.*?(?=@)/' => 'x-access-token:'.REDACTED,
            '/oauth2:.*?(?=@)/' => 'oauth2:'.REDACTED,
            '/((?:https?|postgres|mysql|mongodb|rediss?|mariadb|ftp|sftp|ssh|amqp|amqps|ldap|ldaps|s3):\/\/[^:]+:)[^@]+(@)/i' => '$1'.REDACTED.'$2',
            self::EMAIL_PATTERN => REDACTED,
            '/Bearer\s+[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+/i' => 'Bearer '.REDACTED,
            '/Bearer\s+[^\s,;]+/i' => 'Bearer '.REDACTED,
            '/\bgh[pousr]_[A-Za-z0-9.\-_]{36,}(?![A-Za-z0-9.\-_])/' => REDACTED,
            '/\b(gl(?:pat|cbt|rt)-[A-Za-z0-9\-_]{20,})\b/' => REDACTED,
            '/\b(A(?:KIA|BIA|CCA|SIA)[A-Z0-9]{16})\b/' => REDACTED,
            '/(aws_secret_access_key|AWS_SECRET_ACCESS_KEY)[=:]\s*[\'\"]?([A-Za-z0-9\/+\=]{40})[\'\"]?/i' => '$1='.REDACTED,
            '/(api[_-]?key|apikey|api[_-]?secret|secret[_-]?key)[=:]\s*[\'\"]?[A-Za-z0-9\-_]{16,}[\'\"]?/i' => '$1='.REDACTED,
            '/-----BEGIN [A-Z ]*PRIVATE KEY-----[\s\S]*?-----END [A-Z ]*PRIVATE KEY-----/' => REDACTED,
        ];
        if (! $this->redactEmails) {
            unset($patterns[self::EMAIL_PATTERN]);
        }

        foreach ($patterns as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text);
            if ($text === null) {
                throw new \RuntimeException('Log redaction failed.');
            }
        }

        return $text;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(preg_replace('/[-\s]+/', '_', $key) ?? $key);

        return in_array($normalized, self::SENSITIVE_KEYS, true);
    }
}
