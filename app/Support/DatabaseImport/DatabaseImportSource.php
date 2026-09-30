<?php

namespace App\Support\DatabaseImport;

use InvalidArgumentException;

readonly class DatabaseImportSource
{
    public function __construct(
        public string $type,
        public ?string $uploadId = null,
        public ?string $path = null,
        public ?string $s3StorageUuid = null,
        public bool $dumpAll = false,
        public bool $replaceExisting = false,
        public bool $keepOwners = false,
        public ?string $sqliteDatabase = null,
    ) {
        if (! in_array($type, ['upload', 's3', 'server'], true)) {
            throw new InvalidArgumentException('Invalid database import source.');
        }
    }
}
