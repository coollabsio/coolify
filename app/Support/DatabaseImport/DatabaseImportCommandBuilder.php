<?php

namespace App\Support\DatabaseImport;

use App\Models\ServiceDatabase;
use App\Support\PostgresUser;
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
if is_gzip; then
  gunzip -t "$backup" || fail 'The gzip backup is corrupt or incomplete. Nothing was changed.'
fi

SH;

    /**
     * Streams an all-databases MySQL or MariaDB dump without its system databases. The mysql
     * database holds the users and their passwords (user and global_priv), so restoring it
     * would give the target the source passwords after its next restart. Everything from a
     * switch to a system database (`-- Current Database:` or USE) up to the next switch to
     * another database is dropped, as are the CREATE DATABASE statements of system databases.
     */
    private const MYSQL_SYSTEM_DATABASE_FILTER = <<<'AWK'
function database(line) {
  if (index(line, "`") > 0) { sub(/^[^`]*`/, "", line); sub(/`.*$/, "", line); return tolower(line) }
  gsub(/\/\*[^*]*\*\//, " ", line)
  line = tolower(line)
  sub(/^[ \t]*(use|create[ \t]+(database|schema))[ \t]+/, "", line)
  sub(/^if[ \t]+not[ \t]+exists[ \t]+/, "", line)
  sub(/[ \t;].*$/, "", line)
  return line
}
function is_system(name) { return name == "mysql" || name == "sys" || name == "performance_schema" || name == "information_schema" }
/^-- Current Database: `/ { skip = is_system(database($0)) }
tolower($0) ~ /^[ \t]*use[ \t]/ { skip = is_system(database($0)) }
tolower($0) ~ /^[ \t]*create[ \t]+(database|schema)[ \t]/ { if (is_system(database($0))) { next } skip = 0 }
/^\/\*!40103 SET TIME_ZONE=@OLD_TIME_ZONE/ { skip = 0 }
!skip { print }
AWK;

    /**
     * mysql and mariadb client commands that run a shell command, write or read a file:
     * system, tee, pager, edit and source, as a long command at the start of a line or as
     * a short command (\!, \T, \P, \e, \.) anywhere. An odd backslash run starts a short
     * command; mysqldump writes a backslash in data as "\\" and escapes only 0, n, r, Z and
     * quotes, so its rows do not match.
     */
    private const MYSQL_CLIENT_COMMAND_PATTERN = '(^|[^\\\\])(\\\\\\\\)*\\\\[!TPe.]';

    private const MYSQL_NAMED_CLIENT_COMMAND_PATTERN = '^[[:space:]]*(system|tee|pager|edit|source)([[:space:]]|;|$)';

    /**
     * Prints a PostgreSQL SQL dump without the table data of its COPY ... FROM stdin blocks.
     */
    private const POSTGRES_COPY_DATA_FILTER = 'data { if ($0 == "\\\\.") data = 0; next } { print } /^COPY .* FROM stdin;$/ { data = 1 }';

    /**
     * @param  bool  $keepOwners  PostgreSQL archives: restore object owners and privileges. Off by default,
     *                            because roles from another host (for example RDS) usually do not exist here.
     * @param  string|null  $sqliteDatabase  SQLite: the database file to restore into, validated by the resource.
     * @param  bool  $restoreMysqlUsers  MySQL and MariaDB all-databases backups: also restore the system databases
     *                                   (users, passwords and privileges). Off by default, because the source
     *                                   passwords would replace the ones Coolify stores for this database.
     */
    public function buildRestoreCommand(object $resource, string $path, bool $dumpAll, bool $replaceExisting = false, bool $keepOwners = false, ?string $sqliteDatabase = null, bool $restoreMysqlUsers = false): string
    {
        $script = match ($this->databaseType($resource)) {
            'postgresql' => $this->postgresqlPrelude().($dumpAll ? $this->postgresqlDumpAll() : $this->postgresqlSingle($replaceExisting, $keepOwners)),
            'mysql' => $this->mysql('mysql', 'MYSQL', $dumpAll, $restoreMysqlUsers),
            'mariadb' => $this->mysql('mariadb', 'MARIADB', $dumpAll, $restoreMysqlUsers),
            'mongodb' => $this->mongodb($replaceExisting),
            'sqlite' => $this->sqlite($resource->databaseFilePath($sqliteDatabase)),
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

        // Token separator PostgreSQL treats as whitespace: real whitespace, a /* ... */
        // block comment (also nested or holding asterisks), or a -- comment through the
        // line end. The joined scan maps line ends to '~' (after it deletes every real
        // '~'), so a -- comment ends at the next '~'. Comment forms also match inside
        // string literals, which can only block more input, never less.
        $sep = '([[:space:]]|~|/\\*.*\\*/|--[^~]*~)';

        // A TO/FROM PROGRAM token pair anywhere (so it is also found inside DO blocks,
        // EXECUTE strings and function bodies), a server-side large-object import or
        // export, or ALTER SYSTEM. The pair is matched without the former (^|;) anchor
        // and [^;]* limit, which a ';' inside a string literal or dynamic SQL bypassed.
        $sqlPattern = "(from|to){$sep}+program|lo_(import|export){$sep}*\\(|alter{$sep}+system{$sep}+(set|reset)";
        // psql meta-commands that can run a shell command, anywhere on a line (a meta-command
        // may follow SQL without a ';'). An odd backslash run starts a meta-command; COPY data
        // writes a literal backslash as "\\", so its rows do not match. Blocked: \!, output
        // piped by \o/\out/\g/\gx/\w/\write, \copy ... program, \setenv and the editor commands,
        // the include commands (they run a file that \o or \copy wrote), and backquotes in the
        // arguments of a command. \n, \b, \v and \N are COPY escapes that psql does not know,
        // and psql does not expand backquotes for unknown commands.
        $psqlPattern = '(^|[^\\\\])(\\\\\\\\)*\\\\(!|(o|out|gx?|w|write)[[:space:]][^\\\\]*\||copy[[:space:]].*program|(setenv|e|edit|ef|ev|i|ir|include|include_relative)([[:space:]]|$)|([^nbv[:space:]\\\\]|[nbv][^[:space:]\\\\])[^[:space:]\\\\]*[[:space:]][^\\\\]*`)';
        $escapedSqlPattern = escapeshellarg($sqlPattern);
        $escapedPsqlPattern = escapeshellarg($psqlPattern);
        $contents = "{ gunzip -cf {$escapedPath} 2>/dev/null || cat {$escapedPath}; }";
        // Comments are no longer stripped: deleting the rest of a line on "--" also cut
        // inside string literals, which let "SELECT '--'; COPY ... PROGRAM" slip through.
        // A commented-out PROGRAM statement is now blocked too, which is the safer default.
        // Table data of COPY ... FROM stdin blocks is not scanned: it is data, not SQL, and ordinary
        // text such as "how to program" matched the patterns. pg_dump writes each block as the line
        // "COPY ... FROM stdin;", the rows, and the line "\.". When awk is missing or fails, the
        // scan receives a line that matches, so the restore is blocked.
        $withoutCopyData = static fn (string $source): string => "{ {$source} | LC_ALL=C awk ".escapeshellarg(self::POSTGRES_COPY_DATA_FILTER)." || echo '\\! from program'; }";
        $scan = static fn (string $source): string => $withoutCopyData($source)." | LC_ALL=C grep -Eiq {$escapedPsqlPattern} || ".$withoutCopyData($source)." | tr -d '~' | tr '\\n\\r' '~~' | LC_ALL=C grep -Eiq {$escapedSqlPattern}";
        $customScan = $scan('pg_restore -f - "$inspect" 2>/dev/null');
        $sqlScan = $scan($contents);
        $blockedProgram = 'echo \'Blocked PostgreSQL restore: the backup contains a COPY ... PROGRAM, psql shell command, lo_import/lo_export, or ALTER SYSTEM pattern. If you trust this dump, restore it from the database container terminal.\'; exit 1';
        $blockedInspect = 'echo \'Blocked PostgreSQL restore: unable to inspect custom archive.\'; exit 1';

        return <<<SH
command -v awk >/dev/null 2>&1 || { echo 'Blocked PostgreSQL restore: awk is required to scan the backup.'; exit 1; }
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
     * Sets the PostgreSQL user once (see PostgresUser) and defines the checks that run after the
     * backup format is accepted and before the first change. When no user variable is set,
     * "postgres" is a guess, so a failed connection must stop the restore with a clear error.
     */
    private function postgresqlPrelude(): string
    {
        return 'pg_user='.PostgresUser::shellExpression()."\n".<<<'SH'
check_connection() {
  psql -U "$pg_user" -d template1 -Atc 'SELECT 1' >/dev/null 2>&1 || fail "Cannot connect to PostgreSQL as user \"$pg_user\". Set POSTGRES_USER on the database container to its superuser. Nothing was changed."
}
check_superuser() {
  [ "$(psql -U "$pg_user" -d template1 -Atc 'SELECT rolsuper FROM pg_roles WHERE rolname = current_user' 2>/dev/null)" = t ] || fail "Restoring all databases needs a PostgreSQL superuser, and \"$pg_user\" is not one or cannot connect. Set POSTGRES_USER on the database container to its superuser. Nothing was changed."
}

SH;
    }

    /**
     * pg_dump custom and tar archives are restored with pg_restore, SQL dumps with psql.
     * pg_restore cannot read gzip files, so both clients receive the backup on stdin.
     * SQL cannot replace single objects, so replacing recreates the target database.
     * Archives skip owners and privileges unless they are kept, because a single missing
     * role would roll back the whole single-transaction restore.
     */
    private function postgresqlSingle(bool $replaceExisting, bool $keepOwners): string
    {
        // Every restore runs in one transaction, so a failure part-way rolls back and leaves the
        // current data as it was, also when --clean dropped objects first.
        $owners = $keepOwners ? '' : ' --no-owner --no-acl';
        $clean = $replaceExisting ? ' --clean --if-exists' : '';
        $suffix = bin2hex(random_bytes(16));
        $sqlRestore = $replaceExisting ? "\n  new=coolify_restore_new_{$suffix}\n  old=coolify_restore_old_{$suffix}\n".<<<'SH'

  check_connection
  echo 'SQL backups cannot replace single objects. The backup is restored into a new database first; the current database is replaced only when that restore succeeds.'
  if ! existing=$(printf '%s\n' "SELECT 1 FROM pg_database WHERE datname IN (:'new', :'old');" | psql -v ON_ERROR_STOP=1 -At -v new="$new" -v old="$old" -U "$pg_user" -d template1); then
    fail 'The temporary database names could not be checked. Nothing was changed.'
  fi
  [ -z "$existing" ] || fail 'A temporary database name is already in use. Nothing was changed.'
  createdb -U "$pg_user" "$new" || exit 1
  if ! stream | psql -v ON_ERROR_STOP=1 --single-transaction -U "$pg_user" -d "$new"; then
    PGOPTIONS='-c client_min_messages=warning' dropdb --maintenance-db=template1 -U "$pg_user" --if-exists "$new"
    fail 'The SQL restore failed. The current database was not changed.'
  fi
  if ! printf '%s\n' "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = :'db' AND pid <> pg_backend_pid();" 'ALTER DATABASE :"db" RENAME TO :"old";' 'ALTER DATABASE :"new" RENAME TO :"db";' | psql -v ON_ERROR_STOP=1 -v db="$db" -v old="$old" -v new="$new" -U "$pg_user" -d template1 >/dev/null; then
    # Undo a half-done swap: the current database keeps its name and data.
    printf '%s\n' 'ALTER DATABASE :"old" RENAME TO :"db";' | psql -v db="$db" -v old="$old" -U "$pg_user" -d template1 >/dev/null 2>&1
    PGOPTIONS='-c client_min_messages=warning' dropdb --maintenance-db=template1 -U "$pg_user" --if-exists "$new"
    fail 'The current database is in use and could not be replaced. Nothing was changed.'
  fi
  PGOPTIONS='-c client_min_messages=warning' dropdb --maintenance-db=template1 -U "$pg_user" --if-exists "$old" || exit 1
SH : <<<'SH'

  check_connection
  stream | psql -v ON_ERROR_STOP=1 --single-transaction -U "$pg_user" -d "$db"
SH;

        return <<<SH
db=\${POSTGRES_DB:-\$pg_user}
if [ "\$(stream | head -c 5)" = PGDMP ] || is_tar; then
  check_connection
  stream | pg_restore --exit-on-error --single-transaction{$owners}{$clean} -U "\$pg_user" -d "\$db"
elif ! is_text; then
  fail 'Unsupported PostgreSQL backup format. Use a pg_dump archive (custom or tar format) or an SQL file.'
elif stream | head -c 4096 | grep -q 'PostgreSQL database cluster dump'; then
  fail 'This backup contains all databases. Select "Backup contains all databases" to restore it.'
else{$sqlRestore}
fi
SH;
    }

    /**
     * Checks the backup format before any database is dropped, then recreates the
     * target database and restores archives with pg_restore and SQL with psql.
     * The restore must not stop on the first error: a pg_dumpall dump always creates the
     * superuser role (and creates the target database) that already exist here, and an
     * archive with owners references roles this server may not have. Stopping there would
     * leave the server without the databases that were just dropped.
     * pg_dumpall does not create the postgres database (it expects it to exist), so it is
     * recreated too when POSTGRES_DB names another database.
     */
    private function postgresqlDumpAll(): string
    {
        return <<<'SH'
db=${POSTGRES_DB:-$pg_user}
if [ "$(stream | head -c 5)" = PGDMP ] || is_tar; then
  kind=archive
  stream | pg_restore -l >/dev/null 2>&1 || fail 'pg_restore cannot read this backup archive. Nothing was changed.'
elif is_text; then
  kind=sql
else
  fail 'Unsupported PostgreSQL backup format. Use a pg_dump archive (custom or tar format) or an SQL file. Nothing was changed.'
fi
check_superuser
psql -U "$pg_user" -d template1 -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname IS NOT NULL AND pid <> pg_backend_pid()" || exit 1
psql -U "$pg_user" -d template1 -t -c "SELECT datname FROM pg_database WHERE NOT datistemplate" | xargs -I {} dropdb -U "$pg_user" --maintenance-db=template1 --if-exists {} || exit 1
createdb -U "$pg_user" "$db" || exit 1
[ "$db" = postgres ] || createdb -U "$pg_user" postgres || exit 1
if [ "$kind" = archive ]; then
  stream | pg_restore -U "$pg_user" -d "$db"
else
  stream | psql -U "$pg_user" -d "$db"
fi
SH;
    }

    /**
     * MySQL and MariaDB restore SQL dumps. A tar backup must wrap exactly one dump.
     * The all-databases mode checks the backup before it drops any database and skips
     * the system databases unless their users and privileges are restored as well.
     * The client runs the dump with its client commands disabled: --sandbox on MariaDB
     * (10.5.25, 10.6.18, 10.11.8, 11.4.2 and later), --commands=FALSE on MySQL (8.0.43, 8.4.6,
     * 9.4.0 and later). Older clients do not know these options, so a dump with a client
     * command that runs a shell command or uses a file is refused on every client.
     */
    private function mysql(string $binary, string $prefix, bool $dumpAll, bool $restoreMysqlUsers = false): string
    {
        $commandPattern = escapeshellarg(self::MYSQL_CLIENT_COMMAND_PATTERN);
        $namedCommandPattern = escapeshellarg(self::MYSQL_NAMED_CLIENT_COMMAND_PATTERN);
        $preflight = <<<SH
if is_tar; then
  extract_tar
  use_single_tar_member || fail 'A tar backup must contain exactly one SQL dump. Nothing was changed.'
fi
is_text || fail 'Unsupported backup format. Use an SQL dump. Nothing was changed.'
if stream | LC_ALL=C grep -Eq {$commandPattern} || stream | LC_ALL=C grep -Eiq {$namedCommandPattern}; then
  fail 'Blocked restore: the backup contains a {$binary} client command (system, tee, pager, edit or source). If you trust this dump, restore it from the database container terminal. Nothing was changed.'
fi

SH;
        $clientSafety = <<<SH
client_safety=
if {$binary} --help 2>/dev/null | grep -q -e '--sandbox'; then
  client_safety=--sandbox
elif {$binary} --help 2>/dev/null | grep -q -e '--commands'; then
  client_safety=--commands=FALSE
fi

SH;

        if (! $dumpAll) {
            return $preflight.$clientSafety.<<<SH
if [ "$(stream | grep -c '^USE `')" -gt 1 ]; then
  fail 'This backup contains more than one database. Select "Backup contains all databases" to restore it. Nothing was changed.'
fi
stream | {$binary} \$client_safety -u \${$prefix}_USER -p\${$prefix}_PASSWORD \${$prefix}_DATABASE
SH;
        }

        $root = "{$binary} -u root -p\${{$prefix}_ROOT_PASSWORD}";
        $database = "\${{$prefix}_DATABASE:-default}";

        if ($restoreMysqlUsers) {
            $source = 'stream';
        } else {
            // Without pipefail a failing filter would feed an empty restore, so it ends the
            // input with an invalid statement and the client fails instead.
            $preflight .= "command -v awk >/dev/null 2>&1 || fail 'awk is required to skip the system databases of the backup. Nothing was changed.'\n";
            $source = '{ stream | awk '.escapeshellarg(self::MYSQL_SYSTEM_DATABASE_FILTER)." || echo 'Coolify could not filter the system databases of the backup;'; }";
        }

        return $preflight.$clientSafety.<<<SH
for pid in \$({$root} -N -e "SELECT id FROM information_schema.processlist WHERE user != 'root';"); do {$root} -e "KILL \$pid" 2>/dev/null || true; done
{$root} -N -e "SELECT CONCAT('DROP DATABASE IF EXISTS \\`',schema_name,'\\`;') FROM information_schema.schemata WHERE schema_name NOT IN ('information_schema','mysql','performance_schema','sys');" | {$root} || exit 1
{$root} -e "CREATE DATABASE IF NOT EXISTS \\`{$database}\\`;" || exit 1
{$source} | {$binary} \$client_safety -u root -p\${{$prefix}_ROOT_PASSWORD} {$database}
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
     * SQLite restores a plain or gzip-compressed database file into the selected database
     * file with .restore, which replaces its contents. SQLite reads an empty file (for
     * example from a failed dump) as an empty database, so only real database files are restored.
     */
    private function sqlite(string $file): string
    {
        $file = escapeshellarg($file);

        return <<<SH
stream > "\$backup.db" || fail 'The backup cannot be read. Nothing was changed.'
[ "\$(head -c 15 "\$backup.db")" = 'SQLite format 3' ] || { rm -f "\$backup.db"; fail 'The backup is not a SQLite database. Nothing was changed.'; }
sqlite3 -bail {$file} '.timeout 10000' ".restore \$backup.db"; status=\$?; rm -f "\$backup.db"; exit \$status
SH;
    }
}
