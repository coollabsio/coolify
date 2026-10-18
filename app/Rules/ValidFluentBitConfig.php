<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a custom Fluent Bit classic-mode file for the log drain container.
 *
 * Only a supported subset of sections, directives, and plugins is allowed. With $parsersOnly, the value
 * must only contain [PARSER] and [MULTILINE_PARSER] declarations.
 */
class ValidFluentBitConfig implements ValidationRule
{
    private const CONFIG_SECTIONS = ['SERVICE', 'INPUT', 'FILTER', 'OUTPUT', 'PARSER', 'MULTILINE_PARSER'];

    private const PARSER_SECTIONS = ['PARSER', 'MULTILINE_PARSER'];

    private const PLUGIN_SECTIONS = ['INPUT', 'FILTER', 'OUTPUT'];

    private const DENIED_PLUGINS = ['lua', 'wasm', 'exec', 'exec_wasi', 'file'];

    private const DENIED_KEYS = ['plugins_file'];

    public function __construct(private bool $parsersOnly = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $error = $this->check($value);
        if ($error !== null) {
            $fail("The :attribute is not allowed: {$error}");
        }
    }

    /**
     * Returns the reason the value is not allowed, or null when it is safe.
     */
    public function check(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }
        if (! is_string($value)) {
            return 'it must be a string.';
        }

        $allowedSections = $this->parsersOnly ? self::PARSER_SECTIONS : self::CONFIG_SECTIONS;
        $section = null;

        foreach (preg_split('/\r\n|\r|\n/', $value) as $number => $rawLine) {
            $line = trim($rawLine);
            $lineNumber = $number + 1;
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, '@')) {
                $directive = strtolower(preg_split('/\s+/', $line)[0]);
                if ($this->parsersOnly || $directive !== '@set') {
                    return "line {$lineNumber} uses the {$directive} directive.";
                }

                continue;
            }

            if (str_starts_with($line, '[')) {
                if (! preg_match('/^\[\s*([A-Za-z_]+)\s*\]$/', $line, $matches)) {
                    return "line {$lineNumber} is not a valid section header.";
                }
                $section = strtoupper($matches[1]);
                if (! in_array($section, $allowedSections, true)) {
                    return "line {$lineNumber} uses the [{$section}] section. Allowed sections: ".implode(', ', $allowedSections).'.';
                }

                continue;
            }

            if ($section === null) {
                return "line {$lineNumber} is outside a section.";
            }

            $parts = preg_split('/\s+/', $line, 2);
            $key = strtolower($parts[0]);
            if (! preg_match('/^[a-z_][a-z0-9_.\-]*$/', $key)) {
                return "line {$lineNumber} has an invalid key.";
            }
            if (in_array($key, self::DENIED_KEYS, true)) {
                return "line {$lineNumber} uses the {$key} key.";
            }

            if ($key === 'name' && in_array($section, self::PLUGIN_SECTIONS, true)) {
                $plugin = strtolower(explode('://', trim($parts[1] ?? ''), 2)[0]);
                if (! preg_match('/^[a-z0-9_]+$/', $plugin)) {
                    return "line {$lineNumber} must name a plugin literally.";
                }
                if (in_array($plugin, self::DENIED_PLUGINS, true)) {
                    return "line {$lineNumber} uses the {$plugin} plugin.";
                }
            }
        }

        return null;
    }
}
