<?php

namespace App\Rules;

use App\Support\ValidationPatterns;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class ValidS3BucketName implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! ValidationPatterns::isValidS3BucketName($value)) {
            $fail('The :attribute must be a valid S3 bucket name: 3-63 lowercase letters, numbers, dots, or hyphens; start and end with a letter or number; no consecutive dots, dot-hyphen pairs, or IP address format.');
        }
    }
}
