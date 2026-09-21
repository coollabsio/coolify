<?php

namespace App\Support\DatabaseImport;

use App\Models\ServiceDatabase;
use InvalidArgumentException;

class DatabaseImportCommandBuilder
{
    public function buildRestoreCommand(object $resource, string $path, bool $dumpAll, bool $replaceExisting = false): string
    {
        $path = escapeshellarg($path);

        return match ($this->databaseType($resource)) {
            'postgresql' => $dumpAll
                ? 'psql -U ${POSTGRES_USER} -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname IS NOT NULL AND pid <> pg_backend_pid()" && psql -U ${POSTGRES_USER} -t -c "SELECT datname FROM pg_database WHERE NOT datistemplate" | xargs -I {} dropdb -U ${POSTGRES_USER} --if-exists {} && createdb -U ${POSTGRES_USER} ${POSTGRES_DB:-${POSTGRES_USER:-postgres}} && (gunzip -cf '.$path.' 2>/dev/null || cat '.$path.') | psql -U ${POSTGRES_USER} -d ${POSTGRES_DB:-${POSTGRES_USER:-postgres}}'
                : 'pg_restore --exit-on-error'.($replaceExisting ? ' --clean --if-exists' : '').' -U $POSTGRES_USER -d ${POSTGRES_DB:-${POSTGRES_USER:-postgres}} '.$path,
            'mysql' => $dumpAll
                ? $this->mysqlDumpAll('mysql', 'MYSQL', $path)
                : '(gunzip -cf '.$path.' 2>/dev/null || cat '.$path.') | mysql -u $MYSQL_USER -p$MYSQL_PASSWORD $MYSQL_DATABASE',
            'mariadb' => $dumpAll
                ? $this->mysqlDumpAll('mariadb', 'MARIADB', $path)
                : '(gunzip -cf '.$path.' 2>/dev/null || cat '.$path.') | mariadb -u $MARIADB_USER -p$MARIADB_PASSWORD $MARIADB_DATABASE',
            'mongodb' => 'mongorestore --authenticationDatabase=admin --username $MONGO_INITDB_ROOT_USERNAME --password $MONGO_INITDB_ROOT_PASSWORD --uri mongodb://localhost:27017 --gzip --archive='.$path,
            default => throw new InvalidArgumentException('Database import is not supported for this database type.'),
        };
    }

    public function buildPostgresRestoreScanScript(object $resource, string $path): ?string
    {
        if ($this->databaseType($resource) !== 'postgresql') {
            return null;
        }

        $escapedPath = escapeshellarg($path);

        // Token separator PostgreSQL treats as whitespace: real whitespace or a
        // /* ... */ block comment (used to split keywords like FROM/**/PROGRAM).
        $sep = '([[:space:]]|/\\*[^*]*\\*/)';

        $sqlPattern = "(^|;){$sep}*copy{$sep}+[^;]*(from|to){$sep}+program";
        $psqlPattern = "^{$sep}*\\\\(!|copy{$sep}+[^[:space:]]+.*{$sep}+program|(o|g){$sep}*\\|)";
        $escapedSqlPattern = escapeshellarg($sqlPattern);
        $escapedPsqlPattern = escapeshellarg($psqlPattern);
        $contents = "{ gunzip -cf {$escapedPath} 2>/dev/null || cat {$escapedPath}; }";
        $scan = static fn (string $source): string => "{$source} | sed 's/--.*//' | grep -Eiq {$escapedPsqlPattern} || {$source} | sed 's/--.*//' | tr '\\n\\r\\t' '   ' | grep -Eiq {$escapedSqlPattern}";
        $customScan = $scan('pg_restore -f - "$inspect" 2>/dev/null');
        $sqlScan = $scan($contents);
        $blockedProgram = 'echo \'Blocked PostgreSQL restore: COPY ... PROGRAM and psql shell commands are not allowed.\'; exit 1';
        $blockedInspect = 'echo \'Blocked PostgreSQL restore: unable to inspect custom archive.\'; exit 1';

        return <<<SH
header=\$({$contents} | head -c 5)
if [ "\$header" = 'PGDMP' ]; then
  inspect=\$(mktemp)
  trap 'rm -f "\$inspect"' EXIT
  if ! {$contents} > "\$inspect"; then
    {$blockedInspect}
  fi
  if ! pg_restore -l "\$inspect" >/dev/null 2>&1; then
    {$blockedInspect}
  fi
  if {$customScan}; then
    {$blockedProgram}
  fi
elif {$sqlScan}; then
  {$blockedProgram}
fi
SH;
    }

    public function buildPostgresSafetyCommand(object $resource, string $container, string $path): ?string
    {
        $script = $this->buildPostgresRestoreScanScript($resource, $path);

        if ($script === null) {
            return null;
        }

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
        $rootPassword = '${'.$prefix.'_ROOT_PASSWORD}';
        $database = '${'.$prefix.'_DATABASE:-default}';

        return "for pid in \$({$binary} -u root -p{$rootPassword} -N -e \"SELECT id FROM information_schema.processlist WHERE user != 'root';\"); do {$binary} -u root -p{$rootPassword} -e \"KILL \$pid\" 2>/dev/null || true; done && {$binary} -u root -p{$rootPassword} -N -e \"SELECT CONCAT('DROP DATABASE IF EXISTS \\`',schema_name,'\\`;') FROM information_schema.schemata WHERE schema_name NOT IN ('information_schema','mysql','performance_schema','sys');\" | {$binary} -u root -p{$rootPassword} && {$binary} -u root -p{$rootPassword} -e \"CREATE DATABASE IF NOT EXISTS \\`{$database}\\`;\" && (gunzip -cf {$path} 2>/dev/null || cat {$path}) | {$binary} -u root -p{$rootPassword} {$database}";
    }
}
