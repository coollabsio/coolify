<?php

namespace App\Enums;

enum MigrationStorageDriver: string
{
    case S3 = 's3';
    case LocalSsh = 'local-ssh';
    case Azure = 'azure';
    case Gcs = 'gcs';

    public static function fromAlias(string $value): self
    {
        return match (strtolower($value)) {
            's3', 'aws', 'coolify-cloud', 'coolify_cloud' => self::S3,
            'local-ssh', 'local_ssh', 'local', 'ssh' => self::LocalSsh,
            'azure', 'azure-blob', 'azure_blob' => self::Azure,
            'gcs', 'gcp', 'google' => self::Gcs,
            default => self::from($value),
        };
    }
}
