<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidDockerBuildOptions implements ValidationRule
{
    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($value)) {
            return;
        }

        // List of supported placeholders
        $supportedPlaceholders = [
            'git_branch',
            'git_commit_sha',
            'application_uuid',
            'application_name',
        ];

        // Find all placeholders in the format {{placeholder}}
        preg_match_all('/\{\{([^}]+)\}\}/', $value, $matches);

        if (! empty($matches[1])) {
            foreach ($matches[1] as $placeholder) {
                if (! in_array($placeholder, $supportedPlaceholders)) {
                    $fail("Unsupported placeholder '{$placeholder}'. Allowed: ".implode(', ', $supportedPlaceholders));

                    return;
                }
            }
        }

        // Basic security check - prevent dangerous characters
        $dangerousPatterns = [
            '/;/',           // Command separation
            '/\|/',          // Pipes
            '/&&/',          // Command chaining
            '/\|\|/',        // OR chaining
            '/`/',           // Command substitution
            '/\$\(/',        // Command substitution
            '/>\s*\//',      // Redirects to system paths
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $value)) {
                $fail("The {$attribute} contains potentially unsafe characters or patterns.");

                return;
            }
        }
    }
}
