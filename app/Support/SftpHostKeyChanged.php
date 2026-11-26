<?php

namespace App\Support;

use League\Flysystem\FilesystemException;
use RuntimeException;
use Throwable;

class SftpHostKeyChanged extends RuntimeException implements FilesystemException
{
    public static function atHost(string $host, Throwable $previous): self
    {
        return new self("The SSH host key of the SFTP server {$host} changed. If you expect this change, for example after a reinstall of the server, reset the host key in the storage settings and validate the connection again.", previous: $previous);
    }
}
