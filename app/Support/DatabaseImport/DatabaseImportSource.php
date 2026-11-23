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
        public bool $restoreMysqlUsers = false,
    ) {
        // 's3-upload' is a browser upload to the instance's import storage (only the import form uses it).
        if (! in_array($type, ['upload', 's3', 'server', 's3-upload'], true)) {
            throw new InvalidArgumentException('Invalid database import source.');
        }
    }
}
