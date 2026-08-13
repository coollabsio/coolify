<?php

namespace App\Services\Migration\Storage;

use App\Models\Server;

interface MigrationStorageDriver
{
    /**
     * Upload a file that already exists on the managed server.
     *
     * @return array{driver: string, bucket: ?string, key: string, checksum: ?string, size_bytes: int}
     */
    public function put(Server $server, string $localPath, string $objectKey): array;

    /**
     * Download an object onto the managed server and return the local path.
     */
    public function get(Server $server, string $objectKey, string $destinationPath): string;

    public function exists(Server $server, string $objectKey): bool;

    public function delete(Server $server, string $objectKey): void;

    /**
     * Available disk space in bytes on the managed server, or null if unknown.
     */
    public function diskFree(Server $server): ?int;
}
