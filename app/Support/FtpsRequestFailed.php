<?php

namespace App\Support;

use RuntimeException;

/**
 * A failed curl request of FtpsFilesystemAdapter. The code is the curl error number.
 */
class FtpsRequestFailed extends RuntimeException
{
    /**
     * CURLE_REMOTE_ACCESS_DENIED (the server refused to change to the directory), CURLE_FTP_COULDNT_RETR_FILE, and
     * CURLE_REMOTE_FILE_NOT_FOUND.
     */
    private const NOT_FOUND_ERRORS = [9, 19, 78];

    public function __construct(int $curlError, string $message)
    {
        parent::__construct('FTPS request failed: '.$message, $curlError);
    }

    public function isNotFound(): bool
    {
        return in_array($this->getCode(), self::NOT_FOUND_ERRORS, true);
    }
}
