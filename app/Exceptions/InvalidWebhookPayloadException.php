<?php

namespace App\Exceptions;

use Exception;

/**
 * A webhook payload field has a wrong type or format.
 *
 * Webhook handlers catch this exception and send a clean "Nothing to do."
 * response. The request does not deploy anything.
 */
class InvalidWebhookPayloadException extends Exception
{
    public static function forField(string $field): self
    {
        return new self("Nothing to do. Invalid '{$field}' in the request.");
    }
}
