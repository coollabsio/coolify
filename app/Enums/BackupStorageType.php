<?php

namespace App\Enums;

/**
 * The protocol of a backup storage (`s3_storages.type`).
 */
enum BackupStorageType: string
{
    case S3 = 's3';
    case Sftp = 'sftp';
    case Ftps = 'ftps';
    case Webdav = 'webdav';

    public function label(): string
    {
        return match ($this) {
            self::S3 => 'S3',
            self::Sftp => 'SFTP',
            self::Ftps => 'FTPS',
            self::Webdav => 'WebDAV',
        };
    }

    /**
     * The URL schemes that the endpoint of this type can use.
     *
     * @return array<int, string>
     */
    public function endpointSchemes(): array
    {
        return match ($this) {
            self::S3, self::Webdav => ['https', 'http'],
            self::Sftp => ['sftp'],
            self::Ftps => ['ftps'],
        };
    }

    public function defaultPort(): ?int
    {
        return match ($this) {
            self::S3, self::Webdav => null,
            self::Sftp => 22,
            self::Ftps => 21,
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $type): array => ['value' => $type->value, 'label' => $type->label()], self::cases());
    }
}
