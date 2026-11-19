<?php

namespace App\Support;

/**
 * A storage adapter that deletes a directory only when it is empty, in one request, so a file that another upload
 * writes at the same time is never deleted.
 */
interface DeletesEmptyDirectories
{
    /**
     * @return bool False when the directory is not empty or could not be deleted.
     */
    public function deleteEmptyDirectory(string $path): bool;
}
