<?php

namespace App\Rules;

use App\Support\ValidationPatterns;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class DockerImageFormat implements ValidationRule
{
    /**
     * @param  bool  $allowVariableTag  Allow $VAR tags. Use it only for images that Docker Compose runs, because only Compose fills in the variables.
     */
    public function __construct(private bool $allowVariableTag = false) {}

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
            $lastSlash = strrpos($value, '/');
            $tagColon = strpos($value, ':', $lastSlash === false ? 0 : $lastSlash + 1);
            if ($tagColon !== false) {
                $imageName = substr($value, 0, $tagColon);
                $tag = substr($value, $tagColon + 1);
            }
        }

        if (! ValidationPatterns::isValidDockerImageName($imageName) || ! ValidationPatterns::isValidDockerImageTag($tag, allowVariables: $this->allowVariableTag)) {
            $fail('The :attribute format is invalid. Use image:tag or image@sha256:hash format.');
        }
    }
}
