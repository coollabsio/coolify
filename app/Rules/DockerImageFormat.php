<?php

namespace App\Rules;

use App\Support\ValidationPatterns;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class DockerImageFormat implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute format is invalid. Use image:tag or image@sha256:hash format.');

            return;
        }

        // Check if the value contains ":sha256:" or ":sha" which is incorrect format
        if (preg_match('/:sha256?:/i', $value)) {
            $fail('The :attribute must use @ before sha256 digest (e.g., image@sha256:hash, not image:sha256:hash).');

            return;
        }

        $imageName = $value;
        $tag = null;

        if (preg_match('/\A(.+)@sha256:([a-f0-9]{64})\z/i', $value, $matches) === 1) {
            $imageName = $matches[1];
        } else {
            $lastColon = strrpos($value, ':');
            $lastSlash = strrpos($value, '/');
            if ($lastColon !== false && ($lastSlash === false || $lastColon > $lastSlash)) {
                $imageName = substr($value, 0, $lastColon);
                $tag = substr($value, $lastColon + 1);
            }
        }

        if (! ValidationPatterns::isValidDockerImageName($imageName) || ! ValidationPatterns::isValidDockerImageTag($tag)) {
            $fail('The :attribute format is invalid. Use image:tag or image@sha256:hash format.');
        }
    }
}
