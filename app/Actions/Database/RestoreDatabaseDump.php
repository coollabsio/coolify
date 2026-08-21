<?php

namespace App\Actions\Database;

use App\Models\Server;
use App\Models\ServiceDatabase;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Support\DatabaseBackupFileValidator;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class RestoreDatabaseDump
{
    use AsAction;

    public function handle(object $database, Server $server, string $dumpPath, bool $dumpAll = false): void
    {
        $container = $database->uuid;
        $tmpPath = '/tmp/restore_'.new_public_id();
        $scriptPath = $tmpPath.'.sh';
        $restoreCommand = $this->buildRestoreCommand($database, $tmpPath, $dumpAll);

        if ($restoreCommand === '') {
            throw new RuntimeException('Logical dump restore is not supported for this database type.');
        }

        if ($this->isPostgresql($database) && is_file($dumpPath) && DatabaseBackupFileValidator::fileContainsPostgresqlProgramExecution($dumpPath)) {
            throw new RuntimeException('The dump contains disallowed PostgreSQL restore directives.');
        }

        $restoreCommandBase64 = base64_encode($restoreCommand);

        instant_remote_process([
            'docker cp '.escapeshellarg($dumpPath).' '.escapeshellarg($container.':'.$tmpPath),
            'echo '.escapeshellarg($restoreCommandBase64).' | base64 -d > '.escapeshellarg($scriptPath),
            'chmod +x '.escapeshellarg($scriptPath),
            'docker cp '.escapeshellarg($scriptPath).' '.escapeshellarg($container.':'.$scriptPath),
            'docker exec '.escapeshellarg($container).' sh -c '.escapeshellarg($scriptPath),
            'docker exec '.escapeshellarg($container).' rm -f '.escapeshellarg($tmpPath).' '.escapeshellarg($scriptPath).' || true',
            'rm -f '.escapeshellarg($scriptPath),
        ], $server);
    }

    public function buildRestoreCommand(object $database, string $tmpPath, bool $dumpAll = false): string
    {
        $escapedTmpPath = escapeshellarg($tmpPath);
        $morphClass = $database->getMorphClass();

        if ($morphClass === ServiceDatabase::class) {
            $dbType = $database->databaseType();
            if (str_contains($dbType, 'mysql')) {
                $morphClass = 'mysql';
            } elseif (str_contains($dbType, 'mariadb')) {
                $morphClass = 'mariadb';
            } elseif (str_contains($dbType, 'postgres')) {
                $morphClass = 'postgresql';
            } elseif (str_contains($dbType, 'mongo')) {
                $morphClass = 'mongodb';
            }
        }

        return match ($morphClass) {
            StandaloneMariadb::class, 'mariadb' => $dumpAll
                ? "(gunzip -cf {$escapedTmpPath} 2>/dev/null || cat {$escapedTmpPath}) | mariadb -u root -p\$MARIADB_ROOT_PASSWORD"
                : "mariadb -u \$MARIADB_USER -p\$MARIADB_PASSWORD \$MARIADB_DATABASE < {$escapedTmpPath}",
            StandaloneMysql::class, 'mysql' => $dumpAll
                ? "(gunzip -cf {$escapedTmpPath} 2>/dev/null || cat {$escapedTmpPath}) | mysql -u root -p\$MYSQL_ROOT_PASSWORD"
                : "mysql -u \$MYSQL_USER -p\$MYSQL_PASSWORD \$MYSQL_DATABASE < {$escapedTmpPath}",
            StandalonePostgresql::class, 'postgresql' => $dumpAll
                ? "(gunzip -cf {$escapedTmpPath} 2>/dev/null || cat {$escapedTmpPath}) | psql -U \${POSTGRES_USER} -d \${POSTGRES_DB:-\${POSTGRES_USER:-postgres}}"
                : "pg_restore -U \$POSTGRES_USER -d \${POSTGRES_DB:-\${POSTGRES_USER:-postgres}} {$escapedTmpPath}",
            StandaloneMongodb::class, 'mongodb' => 'mongorestore --authenticationDatabase=admin --username $MONGO_INITDB_ROOT_USERNAME --password $MONGO_INITDB_ROOT_PASSWORD --uri mongodb://localhost:27017 --gzip --archive='.$escapedTmpPath,
            StandaloneClickhouse::class, 'clickhouse' => "clickhouse-client --query \"RESTORE DATABASE \${CLICKHOUSE_DB} FROM File('{$tmpPath}')\"",
            default => '',
        };
    }

    private function isPostgresql(object $database): bool
    {
        $morphClass = $database->getMorphClass();

        if ($morphClass === StandalonePostgresql::class) {
            return true;
        }

        return $morphClass === ServiceDatabase::class && str_contains((string) $database->databaseType(), 'postgres');
    }
}
