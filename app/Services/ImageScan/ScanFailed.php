<?php

namespace App\Services\ImageScan;

use App\Enums\ImageScanErrorCode;
use RuntimeException;

/**
 * Stops a scan with an error code. ScanImage catches it and stores the code on the scan row.
 */
final class ScanFailed extends RuntimeException
{
    /**
     * @param  array<string, int|string>  $context
     */
    public function __construct(public readonly ImageScanErrorCode $errorCode, public readonly array $context = [])
    {
        parent::__construct($errorCode->value);
    }
}
