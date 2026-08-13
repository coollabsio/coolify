<?php

namespace App\Services\Migration;

use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\Server;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Support\ClickhouseBackupCommand;
use RuntimeException;

class MigrationArchiver
{
    public function archiveVolume(Server $server, LocalPersistentVolume $volume, string $destinationPath): int
    {
        $source = filled($volume->host_path) ? $volume->host_path : $volume->name;
        $image = escapeshellarg(coolifyHelperImage().':'.getHelperVersion());
        $verify = blank($volume->host_path)
            ? 'docker volume inspect '.escapeshellarg($source).' >/dev/null'
            : 'test -d '.escapeshellarg($source);

        instant_remote_process([
            $verify,
            'mkdir -p '.escapeshellarg(dirname($destinationPath)),
            'docker run --rm -v '.escapeshellarg($source.':/volume:ro').' '.$image
                .' tar -czf - -C /volume . > '.escapeshellarg($destinationPath),
        ], $server);

        return $this->assertArchive($server, $destinationPath);
    }

    public function archiveFileStorage(Server $server, LocalFileVolume $fileVolume, string $destinationPath): int
    {
        $source = $fileVolume->fs_path;
        if (blank($source)) {
            throw new RuntimeException('File storage is missing a host path.');
        }

        $image = escapeshellarg(coolifyHelperImage().':'.getHelperVersion());
        $isDirectory = $fileVolume->is_directory;

        instant_remote_process([
            $isDirectory ? 'test -d '.escapeshellarg($source) : 'test -e '.escapeshellarg($source),
            'mkdir -p '.escapeshellarg(dirname($destinationPath)),
            'docker run --rm -v '.escapeshellarg(dirname($source).':/volume:ro').' '.$image
                .' tar -czf - -C /volume '.escapeshellarg(basename($source)).' > '.escapeshellarg($destinationPath),
        ], $server);

        return $this->assertArchive($server, $destinationPath);
    }

    public function dumpDatabase(object $database, Server $server, string $destinationPath): array
    {
        $container = $database->uuid;
        instant_remote_process(['mkdir -p '.escapeshellarg(dirname($destinationPath))], $server);

        $dumpAll = false;
        $command = match ($database->getMorphClass()) {
            StandalonePostgresql::class => $this->postgresDump($database, $container, $destinationPath),
            StandaloneMysql::class => $this->mysqlDump($database, $container, $destinationPath),
            StandaloneMariadb::class => $this->mariadbDump($database, $container, $destinationPath),
            StandaloneMongodb::class => $this->mongoDump($database, $container, $destinationPath),
            StandaloneClickhouse::class => null,
            default => null,
        };

        if ($database instanceof StandaloneClickhouse) {
            instant_remote_process(
                ClickhouseBackupCommand::make($container, $database->clickhouse_db, basename($destinationPath), dirname($destinationPath)),
                $server,
            );
        } elseif (is_string($command)) {
            instant_remote_process([$command], $server);
        } else {
            throw new RuntimeException('Logical dumps are not supported for this database type.');
        }

        return [
            'size_bytes' => $this->assertArchive($server, $destinationPath),
            'dump_all' => $dumpAll,
        ];
    }

    public function restoreFileStorage(Server $server, string $archivePath, string $destinationPath): void
    {
        $image = escapeshellarg(coolifyHelperImage().':'.getHelperVersion());
        $parent = dirname($destinationPath);

        instant_remote_process([
            'mkdir -p '.escapeshellarg($parent),
            'docker run --rm -i -v '.escapeshellarg($parent.':/target').' '.$image
                .' tar -xzf - -C /target < '.escapeshellarg($archivePath),
        ], $server);
    }

    public function supportsLogicalDump(object $database): bool
    {
        return in_array($database->getMorphClass(), [
            StandalonePostgresql::class,
            StandaloneMysql::class,
            StandaloneMariadb::class,
            StandaloneMongodb::class,
            StandaloneClickhouse::class,
        ], true);
    }

    private function postgresDump(object $database, string $container, string $destinationPath): string
    {
        $command = 'docker exec';
        if (filled($database->postgres_password)) {
            $command .= ' -e PGPASSWORD='.escapeshellarg($database->postgres_password);
        }

        return $command.' '.escapeshellarg($container)
            .' pg_dump --format=custom --no-acl --no-owner --username '.escapeshellarg($database->postgres_user)
            .' '.escapeshellarg($database->postgres_db)
            .' > '.escapeshellarg($destinationPath);
    }

    private function mysqlDump(object $database, string $container, string $destinationPath): string
    {
        return 'docker exec '.escapeshellarg($container)
            .' mysqldump -u root -p'.escapeshellarg($database->mysql_root_password)
            .' '.escapeshellarg($database->mysql_database)
            .' > '.escapeshellarg($destinationPath);
    }

    private function mariadbDump(object $database, string $container, string $destinationPath): string
    {
        return 'docker exec '.escapeshellarg($container)
            .' mariadb-dump -u root -p'.escapeshellarg($database->mariadb_root_password)
            .' '.escapeshellarg($database->mariadb_database)
            .' > '.escapeshellarg($destinationPath);
    }

    private function mongoDump(object $database, string $container, string $destinationPath): string
    {
        return 'docker exec '.escapeshellarg($container)
            .' mongodump --authenticationDatabase=admin --username '.escapeshellarg($database->mongo_initdb_root_username)
            .' --password '.escapeshellarg($database->mongo_initdb_root_password)
            .' --gzip --archive > '.escapeshellarg($destinationPath);
    }

    private function assertArchive(Server $server, string $path): int
    {
        $size = (int) instant_remote_process(
            ['du -b '.escapeshellarg($path).' | cut -f1'],
            $server,
            throwError: false,
        );

        if ($size <= 0) {
            throw new RuntimeException("Migration archive {$path} is empty or was not created.");
        }

        return $size;
    }
}
