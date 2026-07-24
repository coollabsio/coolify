<?php

namespace App\Actions\Database;

use App\Models\StandalonePostgresql;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class ResolvePostgresqlDataDirectory
{
    use AsAction;

    public function handle(StandalonePostgresql $database, bool $populated = true): string
    {
        $configuration = $database->walBackupConfiguration()->first();
        if (! $configuration) {
            throw new RuntimeException('A WAL-G configuration is required to resolve PostgreSQL PGDATA.');
        }

        ValidatePostgresqlWalGImage::run($database->image, $configuration->postgres_major_version);

        if (! $populated) {
            return $configuration->postgres_major_version >= 18
                ? "/var/lib/postgresql/{$configuration->postgres_major_version}/docker"
                : '/var/lib/postgresql/data';
        }

        $mountPath = $database->persistentStorages()->value('mount_path')
            ?? ($configuration->postgres_major_version >= 18 ? '/var/lib/postgresql' : '/var/lib/postgresql/data');
        $discoveryScript = implode("\n", [
            'if [ -n "${PGDATA:-}" ] && [ -f "$PGDATA/PG_VERSION" ]; then',
            '    printf "%s\\n" "$PGDATA"',
            '    exit 0',
            'fi',
            'marker="$(find '.escapeshellarg($mountPath).' -type f -name PG_VERSION -print -quit)"',
            '[ -n "$marker" ]',
            'dirname "$marker"',
        ]);
        $command = 'docker exec '.escapeshellarg($database->uuid).' sh -c '.escapeshellarg($discoveryScript);
        $dataDirectory = trim((string) instant_remote_process([$command], $database->destination->server));

        $normalizedMountPath = rtrim($mountPath, '/');
        if (blank($dataDirectory) || ($dataDirectory !== $normalizedMountPath && ! str($dataDirectory)->startsWith($normalizedMountPath.'/'))) {
            throw new RuntimeException('Could not resolve the PostgreSQL data directory from PG_VERSION.');
        }

        return $dataDirectory;
    }
}
