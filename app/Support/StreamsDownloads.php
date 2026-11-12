<?php

namespace App\Support;

use League\Flysystem\UnableToReadFile;

/**
 * A storage adapter that writes a file straight to a stream, so a download through Coolify buffers nothing.
 */
interface StreamsDownloads
{
    /**
     * @param  resource  $destination
     * @param  int  $maxBytes  The download never writes more than this. FTPS and WebDAV fail when the server sends more; SFTP reads only this much.
     *
     * @throws UnableToReadFile
     */
    public function download(string $path, $destination, int $maxBytes): void;
}
