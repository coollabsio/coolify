<?php

namespace App\Support\DatabaseImport;

use App\Models\ServiceDatabase;
use InvalidArgumentException;

class DatabaseImportCommandBuilder
{
    public function buildRestoreCommand(object $resource, string $path, bool $dumpAll): string
    {
        $path = escapeshellarg($path);

        return match ($this->databaseType($resource)) {
            'postgresql' => $dumpAll
                ? 'psql -U ${POSTGRES_USER} -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname IS NOT NULL AND pid <> pg_backend_pid()" && psql -U ${POSTGRES_USER} -t -c "SELECT datname FROM pg_database WHERE NOT datistemplate" | xargs -I {} dropdb -U ${POSTGRES_USER} --if-exists {} && createdb -U ${POSTGRES_USER} ${POSTGRES_DB:-${POSTGRES_USER:-postgres}} && (gunzip -cf '.$path.' 2>/dev/null || cat '.$path.') | psql -U ${POSTGRES_USER} -d ${POSTGRES_DB:-${POSTGRES_USER:-postgres}}'
                : 'pg_restore -U $POSTGRES_USER -d ${POSTGRES_DB:-${POSTGRES_USER:-postgres}} '.$path,
            'mysql' => $dumpAll
                ? $this->mysqlDumpAll('mysql', 'MYSQL', $path)
                : 'mysql -u $MYSQL_USER -p$MYSQL_PASSWORD $MYSQL_DATABASE < '.$path,
            'mariadb' => $dumpAll
                ? $this->mysqlDumpAll('mariadb', 'MARIADB', $path)
                : 'mariadb -u $MARIADB_USER -p$MARIADB_PASSWORD $MARIADB_DATABASE < '.$path,
            'mongodb' => 'mongorestore --authenticationDatabase=admin --username $MONGO_INITDB_ROOT_USERNAME --password $MONGO_INITDB_ROOT_PASSWORD --uri mongodb://localhost:27017 --gzip --archive='.$path,
            default => throw new InvalidArgumentException('Database import is not supported for this database type.'),
        };
    }

    public function buildPostgresSafetyCommand(object $resource, string $container, string $path): ?string
    {
        if ($this->databaseType($resource) !== 'postgresql') {
            return null;
        }

        $path = escapeshellarg($path);
        $separator = '([[:space:]]|/\\*[^*]*\\*/)';
        $sqlPattern = escapeshellarg("(^|;){$separator}*copy{$separator}+[^;]*(from|to){$separator}+program");
        $psqlPattern = escapeshellarg("^{$separator}*\\\\(!|copy{$separator}+[^[:space:]]+.*{$separator}+program|(o|g){$separator}*\\|)");
        $contents = "{ gunzip -cf {$path} 2>/dev/null || cat {$path}; }";
        $script = "header=\$({$contents} | head -c 5); if [ \"\$header\" = 'PGDMP' ]; then exit 0; fi; if {$contents} | sed 's/--.*//' | grep -Eiq {$psqlPattern} || {$contents} | sed 's/--.*//' | tr '\n\r\t' '   ' | grep -Eiq {$sqlPattern}; then echo 'Blocked PostgreSQL restore: COPY ... PROGRAM and psql shell commands are not allowed.'; exit 1; fi";

        return 'docker exec '.$container.' sh -c '.escapeshellarg($script);
    }

    public function supports(object $resource): bool
    {
        return in_array($this->databaseType($resource), ['postgresql', 'mysql', 'mariadb', 'mongodb'], true);
    }

    public function databaseType(object $resource): string
    {
        $class = $resource->getMorphClass();
        $type = ($resource instanceof ServiceDatabase || str_contains(strtolower($class), 'service'))
            ? strtolower($resource->databaseType())
            : strtolower($class);

        return match (true) {
            str_contains($type, 'postgres') => 'postgresql',
            str_contains($type, 'mariadb') => 'mariadb',
            str_contains($type, 'mysql') => 'mysql',
            str_contains($type, 'mongo') => 'mongodb',
            default => 'unsupported',
        };
    }

    private function mysqlDumpAll(string $binary, string $prefix, string $path): string
    {
        return "for pid in \$({$binary} -u root -p\${{$prefix}_ROOT_PASSWORD} -N -e \"SELECT id FROM information_schema.processlist WHERE user != 'root';\"); do {$binary} -u root -p\${{$prefix}_ROOT_PASSWORD} -e \"KILL \$pid\" 2>/dev/null || true; done && {$binary} -u root -p\${{$prefix}_ROOT_PASSWORD} -N -e \"SELECT CONCAT('DROP DATABASE IF EXISTS \\`',schema_name,'\\`;') FROM information_schema.schemata WHERE schema_name NOT IN ('information_schema','mysql','performance_schema','sys');\" | {$binary} -u root -p\${{$prefix}_ROOT_PASSWORD} && {$binary} -u root -p\${{$prefix}_ROOT_PASSWORD} -e \"CREATE DATABASE IF NOT EXISTS \\`\${{{$prefix}_DATABASE:-default}}\\`;\" && (gunzip -cf {$path} 2>/dev/null || cat {$path}) | {$binary} -u root -p\${{{$prefix}_ROOT_PASSWORD}} \${{{$prefix}_DATABASE:-default}}";
    }
}
