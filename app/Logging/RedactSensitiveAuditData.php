<?php

namespace App\Logging;

use App\Services\Security\SensitiveDataRedactor;
use Illuminate\Log\Logger;

/**
 * Redacts the audit log like every other channel, but keeps email addresses so the log still records who acted.
 */
final class RedactSensitiveAuditData
{
    public function __construct(private readonly SensitiveDataRedactor $redactor) {}

    public function __invoke(Logger $logger): void
    {
        (new RedactSensitiveData($this->redactor->withoutEmailRedaction()))($logger);
    }
}
