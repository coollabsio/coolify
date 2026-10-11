<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Requires a minimum length for new or changed manual webhook secrets.
 *
 * An empty value disables the webhook and stays allowed. A value equal to the
 * currently stored secret is accepted, so secrets saved before this rule existed
 * keep working until they are changed.
 */
class ManualWebhookSecret implements ValidationRule
{
    public const MIN_LENGTH = 16;

    public function __construct(private ?string $currentValue = null) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            $fail('The :attribute must be a string.');

            return;
        }

        if ($this->currentValue !== null && hash_equals($this->currentValue, $value)) {
            return;
        }

        if (mb_strlen($value) < self::MIN_LENGTH) {
            $fail('The :attribute must be at least '.self::MIN_LENGTH.' characters.');
        }
    }
}
