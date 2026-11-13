<?php

namespace App\Services\ImageScan;

use App\Enums\ImageScanErrorCode;
use App\Models\Server;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Stops a scan with an error code. ScanImage catches it and stores the code on the scan row.
 * The detail is for the log only: users see the fixed message of the error code.
 */
final class ScanFailed extends RuntimeException
{
    private const DETAIL_LIMIT = 1000;

    /**
     * @param  array<string, int|string>  $context
     */
    public function __construct(public readonly ImageScanErrorCode $errorCode, public readonly array $context = [], public readonly ?string $detail = null)
    {
        parent::__construct($detail === null ? $errorCode->value : $errorCode->value.': '.mb_substr($detail, 0, self::DETAIL_LIMIT));
    }

    /**
     * Writes the failure with its detail to the Laravel log, so an admin can find why a scan could not run.
     */
    public function log(Server $server, string $target): void
    {
        Log::warning("Image scan of {$target} on server {$server->name} failed: {$this->getMessage()}", [
            'server_uuid' => $server->uuid,
            'error_code' => $this->errorCode->value,
            'context' => $this->context,
        ]);
    }

    /**
     * The values of the error message, plus the detail for the error log on the UI (`detail`; the message ignores it).
     *
     * @param  array<string, int|string>  $context
     * @return array<string, int|string>
     */
    public function errorContext(array $context = []): array
    {
        $context = [...$context, ...$this->context];

        return $this->detail === null ? $context : [...$context, 'detail' => mb_substr($this->detail, 0, self::DETAIL_LIMIT)];
    }

    /**
     * The last part of the raw command output, for the detail.
     */
    public static function outputTail(?string $output): string
    {
        $output = trim((string) $output);

        return $output === '' ? '(no output)' : mb_substr($output, -self::DETAIL_LIMIT / 2);
    }
}
