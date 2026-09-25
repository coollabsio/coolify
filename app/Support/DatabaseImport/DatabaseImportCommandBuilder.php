<?php

namespace App\Support\DatabaseImport;

use App\Models\ServiceDatabase;
use InvalidArgumentException;

class DatabaseImportCommandBuilder
{
    /**
     * Shell helpers shared by every restore script. The backup inside the database
     * container is plain or gzip-compressed (bz2, xz and zip are decompressed by
     * {@see self::buildNormalizeScript()} before it arrives). Every script decides the
     * backup format before it changes anything in the database.
     */
    private const PRELUDE = <<<'SH'
fail() { echo "$1" >&2; exit 1; }
is_gzip() { [ "$(head -c 2 "$backup" | od -An -tx1 | tr -d ' \n')" = 1f8b ]; }
stream() { if is_gzip; then gunzip -c "$backup"; else cat "$backup"; fi; }
header() { stream | head -c "$1" | od -An -tx1 | tr -d ' \n'; }
is_tar() { [ "$(stream | head -c 262 | tail -c 5)" = ustar ]; }
is_text() { [ "$(stream | head -c 65536 | tr -d '\000' | wc -c | tr -d ' ')" = "$(stream | head -c 65536 | wc -c | tr -d ' ')" ]; }
extract_tar() { work=$(mktemp -d) && trap 'rm -rf "$work"' EXIT && stream | tar -xf - -C "$work" || fail 'The tar backup cannot be extracted.'; }
use_single_tar_member() { [ "$(find "$work" -type f | wc -l | tr -d ' ')" = 1 ] && backup=$(find "$work" -type f); }

SH;

    public function buildRestoreCommand(object $resource, string $path, bool $dumpAll, bool $replaceExisting = false): string
    {
        $script = match ($this->databaseType($resource)) {
            'postgresql' => $dumpAll ? $this->postgresqlDumpAll() : $this->postgresqlSingle($replaceExisting),
            'mysql' => $this->mysql('mysql', 'MYSQL', $dumpAll),
            'mariadb' => $this->mysql('mariadb', 'MARIADB', $dumpAll),
            'mongodb' => $this->mongodb($replaceExisting),
            'sqlite' => $this->sqlite($resource->databaseFilePath()),
            default => throw new InvalidArgumentException('Database import is not supported for this database type.'),
        };

        return 'backup='.escapeshellarg($path)."\n".self::PRELUDE.$script;
    }

    /**
     * Prepares a backup inside the Coolify helper image. bz2, xz and single-file zip
     * backups are decompressed because database images do not ship those tools;
     * plain and gzip backups are moved unchanged. Exits non-zero on any failure.
     */
    public function buildNormalizeScript(string $source, string $target): string
    {
        $source = escapeshellarg($source);
        $target = escapeshellarg($target);

        return <<<SH
f={$source}
out={$target}
case "\$(head -c 6 "\$f" | od -An -tx1 | tr -d ' \\n')" in
  425a68*) bunzip2 -c "\$f" > "\$out" || { echo 'The bz2 backup cannot be decompressed.' >&2; exit 1; } ;;
  fd377a585a00) unxz -c "\$f" > "\$out" || { echo 'The xz backup cannot be decompressed.' >&2; exit 1; } ;;
  504b0304*)
    d=\$(mktemp -d) && unzip -q "\$f" -d "\$d" || { echo 'The zip backup cannot be extracted.' >&2; exit 1; }
    [ "\$(find "\$d" -type f | wc -l | tr -d ' ')" = 1 ] || { echo 'A zip backup must contain exactly one file.' >&2; exit 1; }
    mv "\$(find "\$d" -type f)" "\$out" ;;
  *) mv "\$f" "\$out" ;;
esac
SH;
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
tar_magic=\$({$contents} | head -c 262 | tail -c 5)
if [ "\$header" = 'PGDMP' ] || [ "\$tar_magic" = 'ustar' ]; then
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
        return in_array($this->databaseType($resource), ['postgresql', 'mysql', 'mariadb', 'mongodb', 'sqlite'], true);
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
            str_contains($type, 'sqlite') => 'sqlite',
            default => 'unsupported',
        };
    }

    /**
     * pg_dump custom and tar archives are restored with pg_restore, SQL dumps with psql.
     * pg_restore cannot read gzip files, so both clients receive the backup on stdin.
     * SQL cannot replace single objects, so replacing recreates the target database.
     */
    private function postgresqlSingle(bool $replaceExisting): string
    {
        $clean = $replaceExisting ? ' --clean --if-exists' : '';
        $sqlNotice = $replaceExisting ? <<<'SH'

  echo 'SQL backups cannot replace single objects. The database is recreated before the restore.'
  echo "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = :'db' AND pid <> pg_backend_pid();" | psql -v db="$db" -U $POSTGRES_USER -d template1 >/dev/null || exit 1
  dropdb --maintenance-db=template1 -U $POSTGRES_USER --if-exists "$db" || exit 1
  createdb -U $POSTGRES_USER "$db" || exit 1
SH : '';

        return <<<SH
db=\${POSTGRES_DB:-\${POSTGRES_USER:-postgres}}
if [ "\$(stream | head -c 5)" = PGDMP ] || is_tar; then
  stream | pg_restore --exit-on-error{$clean} -U \$POSTGRES_USER -d "\$db"
elif ! is_text; then
  fail 'Unsupported PostgreSQL backup format. Use a pg_dump archive (custom or tar format) or an SQL file.'
elif stream | head -c 4096 | grep -q 'PostgreSQL database cluster dump'; then
  fail 'This backup contains all databases. Select "Backup contains all databases" to restore it.'
else{$sqlNotice}
  stream | psql -v ON_ERROR_STOP=1 -U \$POSTGRES_USER -d "\$db"
fi
SH;
    }

    /**
     * Checks the backup format before any database is dropped, then recreates the
     * target database and restores archives with pg_restore and SQL with psql.
     */
    private function postgresqlDumpAll(): string
    {
        return <<<'SH'
db=${POSTGRES_DB:-${POSTGRES_USER:-postgres}}
if [ "$(stream | head -c 5)" = PGDMP ] || is_tar; then
  kind=archive
  stream | pg_restore -l >/dev/null 2>&1 || fail 'pg_restore cannot read this backup archive. Nothing was changed.'
elif is_text; then
  kind=sql
else
  fail 'Unsupported PostgreSQL backup format. Use a pg_dump archive (custom or tar format) or an SQL file. Nothing was changed.'
fi
psql -U ${POSTGRES_USER} -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname IS NOT NULL AND pid <> pg_backend_pid()" || exit 1
psql -U ${POSTGRES_USER} -t -c "SELECT datname FROM pg_database WHERE NOT datistemplate" | xargs -I {} dropdb -U ${POSTGRES_USER} --if-exists {} || exit 1
createdb -U ${POSTGRES_USER} "$db" || exit 1
if [ "$kind" = archive ]; then
  stream | pg_restore -U ${POSTGRES_USER} -d "$db"
else
  stream | psql -U ${POSTGRES_USER} -d "$db"
fi
SH;
    }

    /**
     * MySQL and MariaDB restore SQL dumps. A tar backup must wrap exactly one dump.
     * The all-databases mode checks the backup before it drops any database.
     */
    private function mysql(string $binary, string $prefix, bool $dumpAll): string
    {
        $preflight = <<<'SH'
if is_tar; then
  extract_tar
  use_single_tar_member || fail 'A tar backup must contain exactly one SQL dump. Nothing was changed.'
fi
is_text || fail 'Unsupported backup format. Use an SQL dump. Nothing was changed.'

SH;

        if (! $dumpAll) {
            return $preflight.<<<SH
if [ "$(stream | grep -c '^USE `')" -gt 1 ]; then
  fail 'This backup contains more than one database. Select "Backup contains all databases" to restore it. Nothing was changed.'
fi
stream | {$binary} -u \${$prefix}_USER -p\${$prefix}_PASSWORD \${$prefix}_DATABASE
SH;
        }

        $root = "{$binary} -u root -p\${{$prefix}_ROOT_PASSWORD}";
        $database = "\${{$prefix}_DATABASE:-default}";

        return $preflight.<<<SH
for pid in \$({$root} -N -e "SELECT id FROM information_schema.processlist WHERE user != 'root';"); do {$root} -e "KILL \$pid" 2>/dev/null || true; done
{$root} -N -e "SELECT CONCAT('DROP DATABASE IF EXISTS \\`',schema_name,'\\`;') FROM information_schema.schemata WHERE schema_name NOT IN ('information_schema','mysql','performance_schema','sys');" | {$root} || exit 1
{$root} -e "CREATE DATABASE IF NOT EXISTS \\`{$database}\\`;" || exit 1
stream | {$root} {$database}
SH;
    }

    /**
     * MongoDB restores mongodump archives (plain or gzip) and dump directories packed
     * as tar. Replacing existing data drops each restored collection first.
     */
    private function mongodb(bool $replaceExisting): string
    {
        $drop = $replaceExisting ? ' --drop' : '';

        return <<<SH
restore() { mongorestore --authenticationDatabase=admin --username \$MONGO_INITDB_ROOT_USERNAME --password \$MONGO_INITDB_ROOT_PASSWORD --uri mongodb://localhost:27017{$drop} "\$@"; }
if is_tar; then
  extract_tar
  if ! use_single_tar_member; then
    bson=\$(find "\$work" -type f \\( -name '*.bson' -o -name '*.bson.gz' \\) | head -n 1)
    [ -n "\$bson" ] || fail 'The tar backup does not contain a mongodump directory. Nothing was changed.'
    root=\$(dirname "\$(dirname "\$bson")")
    if find "\$root" -type f -name '*.bson.gz' | grep -q .; then restore --gzip --dir="\$root"; else restore --dir="\$root"; fi
    exit \$?
  fi
fi
[ "\$(header 4)" = 6de29981 ] || fail 'Unsupported MongoDB backup format. Use a mongodump archive or a dump directory packed as tar. Single .bson files are not supported. Nothing was changed.'
if is_gzip; then restore --gzip --archive="\$backup"; else restore --archive="\$backup"; fi
SH;
    }

    /**
     * SQLite restores a plain or gzip-compressed database file into the first database
     * file with .restore, which replaces its contents.
     */
    private function sqlite(string $file): string
    {
        $file = escapeshellarg($file);

        return <<<SH
stream > "\$backup.db" || fail 'The backup cannot be read. Nothing was changed.'
sqlite3 -bail {$file} '.timeout 10000' ".restore \$backup.db"; status=\$?; rm -f "\$backup.db"; exit \$status
SH;
    }
}
