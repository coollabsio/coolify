<?php

namespace App\Actions\Database;

use App\Models\PostgresqlWalBackupConfiguration;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class BuildPostgresqlWalGEnvironment
{
    use AsAction;

    public function handle(PostgresqlWalBackupConfiguration $configuration): string
    {
        $configuration->loadMissing(['database', 's3']);

        if (! $configuration->database) {
            throw new RuntimeException('The PostgreSQL database for this WAL-G configuration is unavailable.');
        }
        if (! $configuration->s3) {
            throw new RuntimeException('The S3 storage for this WAL-G configuration is unavailable.');
        }
        $prefix = "s3://{$configuration->s3->bucket}/coolify/postgresql/{$configuration->database->uuid}/pg{$configuration->postgres_major_version}";

        return implode("\n", [
            'WALG_S3_PREFIX='.escapeshellarg($prefix),
            'AWS_ACCESS_KEY_ID='.escapeshellarg((string) $configuration->s3->key),
            'AWS_SECRET_ACCESS_KEY='.escapeshellarg((string) $configuration->s3->secret),
            'AWS_REGION='.escapeshellarg((string) $configuration->s3->region),
            'AWS_ENDPOINT='.escapeshellarg((string) $configuration->s3->endpoint),
            'AWS_S3_FORCE_PATH_STYLE=true',
            'WALG_PREVENT_WAL_OVERWRITE=true',
            'WALG_DELTA_MAX_STEPS=0',
        ])."\n";
    }
}
