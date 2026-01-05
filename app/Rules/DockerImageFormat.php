<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class DockerImageFormat implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Check if the value contains ":sha256:" or ":sha" which is incorrect format
        if (preg_match('/:sha256?:/i', $value)) {
            $fail('The :attribute must use @ before sha256 digest (e.g., image@sha256:hash, not image:sha256:hash).');

            return;
        }

        // Valid formats:
        // 1. image:tag (e.g., nginx:latest)
        // 2. registry/image:tag (e.g., ghcr.io/user/app:v1.2.3)
        // 3. image@sha256:hash (e.g., nginx@sha256:abc123...)
        // 4. registry/image@sha256:hash
        // 5. registry:port/image:tag (e.g., localhost:5000/app:latest)

        $pattern = '/^
            (?:[a-z0-9]+(?:[._-][a-z0-9]+)*(?::[0-9]+)?\/)?  # Optional registry with optional port
            [a-z0-9]+(?:[._\/-][a-z0-9]+)*                    # Image name (required)
            (?::[a-z0-9][a-z0-9._-]*|@sha256:[a-f0-9]{64})?   # Optional :tag or @sha256:hash
        $/ix';

        if (! preg_match($pattern, $value)) {
            $fail('The :attribute format is invalid. Use image:tag or image@sha256:hash format.');
        }
    }
}
