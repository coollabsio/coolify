<?php

use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Support\DatabaseImport\DatabaseImportCommandBuilder;
use Illuminate\Filesystem\Filesystem;

/**
 * These tests execute the generated scripts with `sh` against stub database clients.
 * Every stub appends "name|arguments|first 5 bytes of stdin" to a log, so the tests
 * prove which clients ran, in which order, and that nothing runs before a backup
 * format is rejected. The full stdin of every call is kept as well.
 */
function restoreScriptTempDir(): string
{
    $dir = sys_get_temp_dir().'/coolify-restore-script-'.bin2hex(random_bytes(8));
    mkdir($dir);

    return $dir;
}

function restoreScriptRemoveDir(string $dir): void
{
    (new Filesystem)->deleteDirectory($dir);
}

function restoreScriptHasTool(string $tool): bool
{
    exec('command -v '.escapeshellarg($tool).' >/dev/null 2>&1', $output, $exitCode);

    return $exitCode === 0;
}

/**
 * @param  list<string>  $command
 * @param  array<string, string>  $environment
 * @return array{exit: int, stdout: string, stderr: string}
 */
function restoreScriptProcess(array $command, string $cwd, array $environment = []): array
{
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd,
        $environment + ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
    );

    expect($process)->not->toBeFalse();

    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

/**
 * @param  array<string, string>  $files  relative path => contents
 */
function restoreScriptTar(array $files): string
{
    $dir = restoreScriptTempDir();

    try {
        foreach ($files as $name => $contents) {
            $path = $dir.'/src/'.$name;
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            file_put_contents($path, $contents);
        }

        $result = restoreScriptProcess(['tar', '-cf', $dir.'/out.tar', '-C', $dir.'/src', ...array_keys($files)], $dir);
        expect($result['exit'])->toBe(0, $result['stderr']);

        return (string) file_get_contents($dir.'/out.tar');
    } finally {
        restoreScriptRemoveDir($dir);
    }
}

function restoreScriptCompress(string $tool, string $contents): string
{
    $dir = restoreScriptTempDir();

    try {
        file_put_contents($dir.'/input', $contents);
        $result = restoreScriptProcess([$tool, '-c', $dir.'/input'], $dir);
        expect($result['exit'])->toBe(0, $result['stderr']);

        return $result['stdout'];
    } finally {
        restoreScriptRemoveDir($dir);
    }
}

/**
 * Builds an uncompressed (stored) zip archive, so the fixture does not depend on ext-zip.
 *
 * @param  array<string, string>  $files  file name => contents
 */
function restoreScriptZip(array $files): string
{
    $entries = '';
    $directory = '';

    foreach ($files as $name => $contents) {
        $crc = crc32($contents);
        $size = strlen($contents);
        $offset = strlen($entries);
        $entries .= pack('VvvvvvVVVvv', 0x04034B50, 20, 0, 0, 0, 0x21, $crc, $size, $size, strlen($name), 0).$name.$contents;
        $directory .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, 0, 0, 0, 0x21, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, $offset).$name;
    }

    return $entries.$directory.pack('VvvvvVVv', 0x06054B50, 0, 0, count($files), count($files), strlen($directory), strlen($entries), 0);
}

function restoreScriptFixture(string $name): string
{
    $pgCustom = "PGDMP\x01\x0e\x00\x04\x08\x01\x01\x01".str_repeat("\x00\x01toc", 64);
    $pgSql = "-- PostgreSQL database dump\n\nCREATE TABLE items (id integer);\n";
    $mysqlSql = "-- MySQL dump 10.13\n\nCREATE TABLE `items` (`id` int);\nINSERT INTO `items` VALUES (1);\n";
    $mongoArchive = "\x6d\xe2\x99\x81".str_repeat("\x01\x00archive", 32);
    $bson = pack('V', 22)."\x02name\x00".pack('V', 5)."item\x00\x00";

    return match ($name) {
        'pg-custom' => $pgCustom,
        'pg-custom-gz' => gzencode($pgCustom),
        'pg-tar' => restoreScriptTar(['toc.dat' => $pgCustom, '3001.dat' => "1\n\\.\n"]),
        'pg-tar-gz' => gzencode(restoreScriptTar(['toc.dat' => $pgCustom, '3001.dat' => "1\n\\.\n"])),
        'pg-sql' => $pgSql,
        'pg-sql-gz' => gzencode($pgSql),
        'pg-cluster' => "--\n-- PostgreSQL database cluster dump\n--\n\nCREATE ROLE app;\n",
        'garbage' => str_repeat("\x00\xff\x10\x80binary\x00", 64),
        'mysql-sql' => $mysqlSql,
        'mysql-sql-gz' => gzencode($mysqlSql),
        'mysql-tar' => restoreScriptTar(['backup.sql' => $mysqlSql]),
        'mysql-tar-two' => restoreScriptTar(['app.sql' => $mysqlSql, 'other.sql' => $mysqlSql]),
        'mysql-cluster' => "-- MySQL dump 10.13\n\nCREATE DATABASE `app`;\nUSE `app`;\nCREATE TABLE `items` (`id` int);\nUSE `mysql`;\nINSERT INTO `user` VALUES ();\n",
        'mysql-all-databases' => mysqlAllDatabasesDump(),
        'mysql-all-databases-gz' => gzencode(mysqlAllDatabasesDump()),
        'mysql-two-databases' => "-- MySQL dump 10.13\n\nCREATE DATABASE `app`;\nUSE `app`;\nCREATE TABLE `items` (`id` int);\nCREATE DATABASE `other`;\nUSE `other`;\nCREATE TABLE `notes` (`t` text);\n",
        'mysql-one-database' => "-- MySQL dump 10.13\n\nCREATE DATABASE `app`;\nUSE `app`;\nCREATE TABLE `items` (`id` int);\n",
        'mongo-archive' => $mongoArchive,
        'mongo-archive-gz' => gzencode($mongoArchive),
        'mongo-dump-tar' => restoreScriptTar(['dump/app/items.bson' => $bson, 'dump/app/items.metadata.json' => '{"indexes":[]}']),
        'mongo-dump-gz-tar' => restoreScriptTar(['dump/app/items.bson.gz' => gzencode($bson), 'dump/app/items.metadata.json.gz' => gzencode('{"indexes":[]}')]),
        'mongo-archive-tar' => restoreScriptTar(['app.archive' => $mongoArchive]),
        'mongo-notes-tar' => restoreScriptTar(['notes.txt' => 'hello', 'readme.txt' => 'world']),
        'mongo-bson' => $bson,
    };
}

/**
 * An all-databases dump in the layout of mysqldump and mariadb-dump: application databases
 * around the mysql and sys system databases, which hold the users and their passwords.
 */
function mysqlAllDatabasesDump(): string
{
    return <<<'SQL'
-- MariaDB dump 10.19
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;

--
-- Current Database: `app`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `app` /*!40100 DEFAULT CHARACTER SET utf8mb4 */;

USE `app`;
CREATE TABLE `items` (`id` int);
INSERT INTO `items` VALUES (1),(2);

--
-- Current Database: `mysql`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `mysql` /*!40100 DEFAULT CHARACTER SET utf8mb4 */;

USE `mysql`;
DROP TABLE IF EXISTS `global_priv`;
INSERT INTO `global_priv` VALUES ('localhost','root','{"authentication_string":"*SOURCE"}');
INSERT INTO `user` VALUES ('%','app_user','*SOURCE');
DELIMITER ;;
CREATE PROCEDURE `system_proc`() BEGIN SELECT 1; END ;;
DELIMITER ;

--
-- Current Database: `shop`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `shop` /*!40100 DEFAULT CHARACTER SET utf8mb4 */;

USE `shop`;
INSERT INTO `orders` VALUES (7);

--
-- Current Database: `sys`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `sys`;

USE `sys`;
INSERT INTO `sys_config` VALUES ('source');
USE performance_schema;
INSERT INTO `setup_actors` VALUES ('source');
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
-- Dump completed

SQL;
}

function restoreScriptResource(string $engine): object
{
    $class = match ($engine) {
        'postgresql' => StandalonePostgresql::class,
        'mysql' => StandaloneMysql::class,
        'mariadb' => StandaloneMariadb::class,
        'mongodb' => StandaloneMongodb::class,
    };

    $resource = Mockery::mock($class);
    $resource->shouldReceive('getMorphClass')->andReturn($class);

    return $resource;
}

/**
 * @return array{exit: int, stdout: string, stderr: string, calls: list<array{name: string, args: string, header: string, stdin: string}>, dir: string, backup: string, leftovers: list<string>}
 */
function restoreScriptRun(string $engine, string $contents, bool $dumpAll = false, bool $replaceExisting = false, bool $keepOwners = false, bool $restoreMysqlUsers = false): array
{
    $dir = restoreScriptTempDir();

    try {
        $log = $dir.'/calls.log';
        file_put_contents($log, '');
        mkdir($dir.'/bin');
        mkdir($dir.'/stdin');

        foreach (['pg_restore', 'psql', 'dropdb', 'createdb', 'mysql', 'mariadb', 'mongorestore'] as $client) {
            $escapedLog = escapeshellarg($log);
            $escapedStdin = escapeshellarg($dir.'/stdin');
            file_put_contents($dir.'/bin/'.$client, <<<SH
#!/bin/sh
header=''
call=\$(wc -l < {$escapedLog} | tr -d ' ')
if [ ! -t 0 ]; then
    cat > {$escapedStdin}/\$call
    header=\$(head -c 5 {$escapedStdin}/\$call)
fi
printf '%s|%s|%s\\n' '{$client}' "\$*" "\$header" >> {$escapedLog}
exit 0
SH);
            chmod($dir.'/bin/'.$client, 0755);
        }

        $backup = $dir.'/backup file';
        file_put_contents($backup, $contents);

        $script = (new DatabaseImportCommandBuilder)->buildRestoreCommand(restoreScriptResource($engine), $backup, $dumpAll, $replaceExisting, $keepOwners, restoreMysqlUsers: $restoreMysqlUsers);

        $result = restoreScriptProcess(['sh', '-c', $script], $dir, [
            'PATH' => $dir.'/bin'.PATH_SEPARATOR.(getenv('PATH') ?: '/usr/bin:/bin'),
            'TMPDIR' => $dir,
            'POSTGRES_USER' => 'postgres',
            'POSTGRES_DB' => 'app',
            'MYSQL_USER' => 'app_user',
            'MYSQL_PASSWORD' => 'app_pass',
            'MYSQL_DATABASE' => 'app',
            'MYSQL_ROOT_PASSWORD' => 'root_pass',
            'MARIADB_USER' => 'app_user',
            'MARIADB_PASSWORD' => 'app_pass',
            'MARIADB_DATABASE' => 'app',
            'MARIADB_ROOT_PASSWORD' => 'root_pass',
            'MONGO_INITDB_ROOT_USERNAME' => 'root',
            'MONGO_INITDB_ROOT_PASSWORD' => 'mongo_pass',
        ]);

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($log)), fn (string $line): bool => $line !== ''));
        $calls = array_map(function (string $line, int $index) use ($dir): array {
            [$name, $args, $header] = array_pad(explode('|', $line, 3), 3, '');
            $stdin = $dir.'/stdin/'.$index;

            return ['name' => $name, 'args' => $args, 'header' => $header, 'stdin' => is_file($stdin) ? (string) file_get_contents($stdin) : ''];
        }, $lines, array_keys($lines));

        return $result + [
            'calls' => $calls,
            'dir' => $dir,
            'backup' => $backup,
            'leftovers' => glob($dir.'/tmp.*') ?: [],
        ];
    } finally {
        restoreScriptRemoveDir($dir);
    }
}

/**
 * @param  list<array{name: string, args: string, header: string}>  $calls
 * @param  list<array{0: string, 1: string, 2: list<string>}>  $expected  client, stdin header, argument fragments
 */
function restoreScriptExpectCalls(array $calls, array $expected): void
{
    expect(array_map(fn (array $call): string => $call['name'].'|'.$call['header'], $calls))
        ->toBe(array_map(fn (array $call): string => $call[0].'|'.$call[1], $expected));

    foreach ($expected as $index => $call) {
        foreach ($call[2] as $fragment) {
            expect($calls[$index]['args'])->toContain($fragment);
        }
    }
}

/**
 * @return array{exit: int, stdout: string, stderr: string, output: ?string, sourceExists: bool}
 */
function restoreScriptNormalize(string $contents): array
{
    $dir = restoreScriptTempDir();

    try {
        $source = $dir.'/uploaded backup';
        $target = $dir.'/normalized backup';
        file_put_contents($source, $contents);

        $result = restoreScriptProcess(
            ['sh', '-c', (new DatabaseImportCommandBuilder)->buildNormalizeScript($source, $target)],
            $dir,
            ['TMPDIR' => $dir],
        );

        return $result + [
            'output' => is_file($target) ? (string) file_get_contents($target) : null,
            'sourceExists' => file_exists($source),
        ];
    } finally {
        restoreScriptRemoveDir($dir);
    }
}

/**
 * Runs a SQL replacement against stateful PostgreSQL client stubs. Database values represent
 * their data, so accidental drops and a failed swap are visible in the final database state.
 *
 * @return array{exit: int, stdout: string, stderr: string, databases: array<string, string>}
 */
function postgresReplacementScriptRun(string $target = 'app', string $failure = '', bool $gzip = false, ?string $contents = null): array
{
    $dir = restoreScriptTempDir();

    try {
        $backup = $dir.'/backup.sql';
        file_put_contents($backup, $contents ?? ($gzip ? gzencode('SELECT 1;') : 'SELECT 1;'));
        $script = (new DatabaseImportCommandBuilder)->buildRestoreCommand(restoreScriptResource('postgresql'), $backup, false, true);
        preg_match('/new=(\w+)/', $script, $new);
        preg_match('/old=(\w+)/', $script, $old);
        $databases = ['coolify_restore_new' => 'unrelated-new', 'coolify_restore_old' => 'unrelated-old', $target => 'original'];
        if (in_array($failure, ['new_collision', 'old_collision'], true)) {
            $databases[$failure === 'new_collision' ? $new[1] : $old[1]] = 'collision';
        }
        $state = $dir.'/databases.json';
        file_put_contents($state, json_encode($databases));
        mkdir($dir.'/bin');

        $client = <<<'PHP'
<?php
$state = getenv('PG_STATE');
$databases = json_decode(file_get_contents($state), true);
$name = basename($argv[0]);
$args = array_slice($argv, 1);
$sql = stream_get_contents(STDIN);
$failure = getenv('PG_FAILURE');
$save = static function () use ($state, &$databases): void { file_put_contents($state, json_encode($databases)); };
if ($name === 'dropdb') {
    unset($databases[end($args)]);
} elseif ($name === 'createdb') {
    $database = end($args);
    if (isset($databases[$database])) { exit(1); }
    $databases[$database] = 'empty';
} else {
    $variables = [];
    foreach ($args as $index => $arg) {
        if ($arg === '-v') {
            [$key, $value] = explode('=', $args[$index + 1], 2);
            $variables[$key] = $value;
        }
    }
    if (str_contains($sql, 'SELECT 1 FROM pg_database')) {
        if (isset($databases[$variables['new']]) || isset($databases[$variables['old']])) { echo "1\n"; }
    } elseif (str_contains($sql, 'ALTER DATABASE')) {
        preg_match_all('/ALTER DATABASE :"(\w+)" RENAME TO :"(\w+)";/', $sql, $renames, PREG_SET_ORDER);
        foreach ($renames as $rename) {
            $from = $variables[$rename[1]];
            $to = $variables[$rename[2]];
            if (! isset($databases[$from]) || isset($databases[$to]) || ($failure === 'swap' && $rename[1] === 'new')) {
                $save();
                exit(1);
            }
            $databases[$to] = $databases[$from];
            unset($databases[$from]);
        }
    } else {
        if ($failure === 'restore') { exit(1); }
        $database = $args[array_search('-d', $args, true) + 1];
        if (! isset($databases[$database])) { exit(1); }
        $databases[$database] = 'restored';
    }
}
$save();
PHP;

        foreach (['psql', 'createdb', 'dropdb'] as $name) {
            file_put_contents($dir.'/bin/'.$name, '#!'.PHP_BINARY."\n".$client);
            chmod($dir.'/bin/'.$name, 0755);
        }

        return restoreScriptProcess(['sh', '-c', $script], $dir, [
            'PATH' => $dir.'/bin'.PATH_SEPARATOR.(getenv('PATH') ?: '/usr/bin:/bin'),
            'POSTGRES_USER' => 'postgres',
            'POSTGRES_DB' => $target,
            'PG_STATE' => $state,
            'PG_FAILURE' => $failure,
        ]) + ['databases' => json_decode(file_get_contents($state), true)];
    } finally {
        restoreScriptRemoveDir($dir);
    }
}

test('SQL replacement preserves unrelated PostgreSQL databases with the former scratch names', function (string $target, bool $gzip) {
    $run = postgresReplacementScriptRun($target, gzip: $gzip);
    $expected = ['coolify_restore_new' => 'unrelated-new', 'coolify_restore_old' => 'unrelated-old', $target => 'restored'];

    expect($run['exit'])->toBe(0, $run['stderr'])
        ->and($run['databases'])->toEqual($expected);
})->with(['app', 'coolify_restore_new', 'coolify_restore_old'])->with([false, true]);

test('failed SQL replacement preserves every existing PostgreSQL database and removes only its own scratch database', function (string $failure) {
    $run = postgresReplacementScriptRun(failure: $failure);

    expect($run['exit'])->toBe(1)
        ->and($run['databases'])->toEqual(['coolify_restore_new' => 'unrelated-new', 'coolify_restore_old' => 'unrelated-old', 'app' => 'original']);
})->with(['restore', 'swap']);

dataset('corrupt restore gzip', [
    'missing footer' => fn (string $contents): string => substr(gzencode($contents), 0, -8),
    'truncated later member' => fn (string $contents): string => gzencode($contents).substr(gzencode('SELECT 2;'), 0, 10),
]);

test('corrupt gzip SQL replacement preserves every existing PostgreSQL database', function (Closure $corrupt) {
    $run = postgresReplacementScriptRun(contents: $corrupt('SELECT 1;'));

    expect($run['exit'])->toBe(1)
        ->and($run['stderr'])->toContain('The gzip backup is corrupt or incomplete. Nothing was changed.')
        ->and($run['databases'])->toEqual(['coolify_restore_new' => 'unrelated-new', 'coolify_restore_old' => 'unrelated-old', 'app' => 'original']);
})->with('corrupt restore gzip');

test('rejects corrupt gzip backups before any database client runs', function (string $engine, string $fixture, bool $dumpAll, bool $replaceExisting, Closure $corrupt) {
    $run = restoreScriptRun($engine, $corrupt(restoreScriptFixture($fixture)), $dumpAll, $replaceExisting);

    expect($run['exit'])->toBe(1)
        ->and($run['stderr'])->toContain('The gzip backup is corrupt or incomplete. Nothing was changed.')
        ->and($run['calls'])->toBe([])
        ->and($run['leftovers'])->toBe([]);
})->with([
    'single PostgreSQL SQL' => ['postgresql', 'pg-sql', false, false],
    'PostgreSQL archive replacement' => ['postgresql', 'pg-custom', false, true],
    'all PostgreSQL databases' => ['postgresql', 'pg-sql', true, false],
    'single MySQL database' => ['mysql', 'mysql-sql', false, false],
    'all MySQL databases' => ['mysql', 'mysql-sql', true, false],
    'all MariaDB databases' => ['mariadb', 'mysql-sql', true, false],
    'MongoDB replacement' => ['mongodb', 'mongo-archive', false, true],
])->with('corrupt restore gzip');

test('SQL replacement refuses scratch database name collisions without changing existing data', function (string $failure) {
    $run = postgresReplacementScriptRun(failure: $failure);

    expect($run['exit'])->toBe(1)
        ->and($run['databases'])->toHaveCount(4)
        ->and($run['databases']['app'])->toBe('original')
        ->and($run['databases']['coolify_restore_new'])->toBe('unrelated-new')
        ->and($run['databases']['coolify_restore_old'])->toBe('unrelated-old')
        ->and(array_values($run['databases']))->toContain('collision');
})->with(['new_collision', 'old_collision']);

test('restores single PostgreSQL archives with pg_restore and SQL with psql', function (string $fixture, bool $replaceExisting, array $expectedCalls) {
    $run = restoreScriptRun('postgresql', restoreScriptFixture($fixture), false, $replaceExisting);

    expect($run['exit'])->toBe(0, $run['stderr']);
    restoreScriptExpectCalls($run['calls'], $expectedCalls);
})->with([
    'custom archive' => ['pg-custom', false, [['pg_restore', 'PGDMP', ['--exit-on-error --single-transaction --no-owner --no-acl -U postgres -d app']]]],
    'custom archive replacing existing objects' => ['pg-custom', true, [['pg_restore', 'PGDMP', ['--exit-on-error --single-transaction --no-owner --no-acl --clean --if-exists -U postgres -d app']]]],
    'gzip custom archive' => ['pg-custom-gz', false, [['pg_restore', 'PGDMP', ['--exit-on-error --single-transaction --no-owner --no-acl -U postgres -d app']]]],
    'gzip custom archive replacing existing objects' => ['pg-custom-gz', true, [['pg_restore', 'PGDMP', ['--exit-on-error --single-transaction --no-owner --no-acl --clean --if-exists -U postgres -d app']]]],
    'tar archive' => ['pg-tar', false, [['pg_restore', 'toc.d', ['--exit-on-error --single-transaction --no-owner --no-acl -U postgres -d app']]]],
    'gzip tar archive' => ['pg-tar-gz', false, [['pg_restore', 'toc.d', ['--exit-on-error --single-transaction --no-owner --no-acl -U postgres -d app']]]],
    'plain SQL' => ['pg-sql', false, [['psql', '-- Po', ['-v ON_ERROR_STOP=1 --single-transaction -U postgres -d app']]]],
    'gzip SQL' => ['pg-sql-gz', false, [['psql', '-- Po', ['-v ON_ERROR_STOP=1 --single-transaction -U postgres -d app']]]],
    // SQL cannot replace single objects: it restores into a new database, and only a
    // successful restore replaces the current one, so a failure changes nothing.
    'plain SQL replacing existing objects' => ['pg-sql', true, [
        ['psql', 'SELEC', ['-v ON_ERROR_STOP=1 -At', '-v new=coolify_restore_new_', '-v old=coolify_restore_old_']],
        ['createdb', '', ['-U postgres coolify_restore_new_']],
        ['psql', '-- Po', ['-v ON_ERROR_STOP=1 --single-transaction -U postgres -d coolify_restore_new_']],
        ['psql', 'SELEC', ['-v ON_ERROR_STOP=1 -v db=app -v old=coolify_restore_old_', '-v new=coolify_restore_new_', '-U postgres -d template1']],
        ['dropdb', '', ['--maintenance-db=template1 -U postgres --if-exists coolify_restore_old_']],
    ]],
    'gzip SQL replacing existing objects' => ['pg-sql-gz', true, [
        ['psql', 'SELEC', ['-v ON_ERROR_STOP=1 -At', '-v new=coolify_restore_new_', '-v old=coolify_restore_old_']],
        ['createdb', '', ['-U postgres coolify_restore_new_']],
        ['psql', '-- Po', ['-v ON_ERROR_STOP=1 --single-transaction -U postgres -d coolify_restore_new_']],
        ['psql', 'SELEC', ['-v ON_ERROR_STOP=1 -v db=app -v old=coolify_restore_old_', '-v new=coolify_restore_new_', '-U postgres -d template1']],
        ['dropdb', '', ['--maintenance-db=template1 -U postgres --if-exists coolify_restore_old_']],
    ]],
]);

test('keeps PostgreSQL owners and privileges only when requested', function (string $fixture) {
    $run = restoreScriptRun('postgresql', restoreScriptFixture($fixture), keepOwners: true);

    expect($run['exit'])->toBe(0, $run['stderr']);
    restoreScriptExpectCalls($run['calls'], [['pg_restore', 'PGDMP', ['--exit-on-error --single-transaction -U postgres -d app']]]);
    expect($run['calls'][0]['args'])->not->toContain('--no-owner')->not->toContain('--no-acl');
})->with(['custom archive' => ['pg-custom'], 'gzip custom archive' => ['pg-custom-gz']]);

test('rejects unsupported single PostgreSQL backups before calling any client', function (string $fixture, string $message) {
    $run = restoreScriptRun('postgresql', restoreScriptFixture($fixture));

    expect($run['exit'])->toBe(1)
        ->and($run['stderr'])->toContain($message)
        ->and($run['calls'])->toBe([]);
})->with([
    'cluster SQL dump' => ['pg-cluster', 'This backup contains all databases.'],
    'binary garbage' => ['garbage', 'Unsupported PostgreSQL backup format'],
]);

test('restores PostgreSQL backups containing all databases after checking the format', function (string $fixture, array $restoreCall) {
    $run = restoreScriptRun('postgresql', restoreScriptFixture($fixture), dumpAll: true);

    $recreate = [
        ['psql', '', ['-U postgres -c', 'pg_terminate_backend']],
        ['psql', '', ['-U postgres -t -c', 'SELECT datname FROM pg_database']],
        ['createdb', '', ['-U postgres app']],
    ];
    $inspect = $restoreCall[0] === 'pg_restore' ? [['pg_restore', 'PGDMP', ['-l']]] : [];

    expect($run['exit'])->toBe(0, $run['stderr']);
    restoreScriptExpectCalls($run['calls'], [...$inspect, ...$recreate, $restoreCall]);
})->with([
    'custom archive' => ['pg-custom', ['pg_restore', 'PGDMP', ['-U postgres -d app']]],
    'gzip custom archive' => ['pg-custom-gz', ['pg_restore', 'PGDMP', ['-U postgres -d app']]],
    'plain SQL' => ['pg-sql', ['psql', '-- Po', ['-U postgres -d app']]],
    'gzip SQL' => ['pg-sql-gz', ['psql', '-- Po', ['-U postgres -d app']]],
]);

test('rejects unsupported PostgreSQL backups containing all databases before dropping anything', function () {
    $run = restoreScriptRun('postgresql', restoreScriptFixture('garbage'), dumpAll: true);

    expect($run['exit'])->toBe(1)
        ->and($run['stderr'])->toContain('Unsupported PostgreSQL backup format')->toContain('Nothing was changed.')
        ->and($run['calls'])->toBe([]);
});

test('restores single MySQL and MariaDB SQL backups', function (string $engine, string $fixture) {
    $run = restoreScriptRun($engine, restoreScriptFixture($fixture));

    expect($run['exit'])->toBe(0, $run['stderr'])
        ->and($run['leftovers'])->toBe([]);
    restoreScriptExpectCalls($run['calls'], [[$engine, '-- My', ['-u app_user -papp_pass app']]]);
})->with(['mysql', 'mariadb'])->with([
    'SQL' => 'mysql-sql',
    'gzip SQL' => 'mysql-sql-gz',
    'tar with one SQL file' => 'mysql-tar',
    'dump of one database made with --databases' => 'mysql-one-database',
]);

test('restores MySQL and MariaDB backups containing all databases after checking the format', function (string $engine, string $fixture) {
    $run = restoreScriptRun($engine, restoreScriptFixture($fixture), dumpAll: true);

    expect($run['exit'])->toBe(0, $run['stderr'])
        ->and($run['leftovers'])->toBe([]);
    restoreScriptExpectCalls($run['calls'], [
        [$engine, '', ['-u root -proot_pass -N -e', 'information_schema.processlist']],
        [$engine, '', ['-u root -proot_pass -N -e', 'DROP DATABASE IF EXISTS']],
        [$engine, '', ['-u root -proot_pass']],
        [$engine, '', ['-u root -proot_pass -e CREATE DATABASE IF NOT EXISTS `app`;']],
        [$engine, '-- My', ['-u root -proot_pass app']],
    ]);
    expect(end($run['calls'])['args'])->toBe('-u root -proot_pass app');
})->with(['mysql', 'mariadb'])->with([
    'SQL' => 'mysql-sql',
    'gzip SQL' => 'mysql-sql-gz',
    'tar with one SQL file' => 'mysql-tar',
    'SQL that uses the mysql schema' => 'mysql-cluster',
]);

test('skips the MySQL and MariaDB system databases of an all-databases backup by default', function (string $engine, string $fixture) {
    $run = restoreScriptRun($engine, restoreScriptFixture($fixture), dumpAll: true);

    expect($run['exit'])->toBe(0, $run['stderr']);
    $restore = end($run['calls']);
    expect($restore['args'])->toBe('-u root -proot_pass app')
        ->and($restore['stdin'])
        ->toContain('INSERT INTO `items` VALUES (1),(2);')
        ->toContain('CREATE DATABASE /*!32312 IF NOT EXISTS*/ `app`')
        ->toContain('USE `shop`;')
        ->toContain('INSERT INTO `orders` VALUES (7);')
        ->toContain('/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;')
        ->not->toContain('global_priv')
        ->not->toContain('app_user')
        ->not->toContain('*SOURCE')
        ->not->toContain('system_proc')
        ->not->toContain('`mysql`')
        ->not->toContain('`sys`')
        ->not->toContain('sys_config')
        ->not->toContain('setup_actors');
})->with(['mysql', 'mariadb'])->with([
    'SQL' => 'mysql-all-databases',
    'gzip SQL' => 'mysql-all-databases-gz',
]);

test('restores the MySQL and MariaDB system databases of an all-databases backup when requested', function (string $engine) {
    $run = restoreScriptRun($engine, restoreScriptFixture('mysql-all-databases-gz'), dumpAll: true, restoreMysqlUsers: true);

    expect($run['exit'])->toBe(0, $run['stderr'])
        ->and(end($run['calls'])['stdin'])->toBe(mysqlAllDatabasesDump());
})->with(['mysql', 'mariadb']);

test('fails an all-databases MySQL restore before dropping anything when awk is missing', function () {
    $dir = restoreScriptTempDir();

    try {
        mkdir($dir.'/bin');
        foreach (['sh', 'head', 'od', 'tr', 'cat', 'gunzip', 'tail', 'wc', 'grep', 'mktemp', 'find'] as $tool) {
            exec('command -v '.escapeshellarg($tool), $path);
            if ($path !== []) {
                symlink(end($path), $dir.'/bin/'.$tool);
            }
            $path = [];
        }
        file_put_contents($dir.'/bin/mysql', "#!/bin/sh\necho called >> ".escapeshellarg($dir.'/calls')."\n");
        chmod($dir.'/bin/mysql', 0755);
        file_put_contents($dir.'/backup', mysqlAllDatabasesDump());

        $script = (new DatabaseImportCommandBuilder)->buildRestoreCommand(restoreScriptResource('mysql'), $dir.'/backup', true);
        $result = restoreScriptProcess([$dir.'/bin/sh', '-c', $script], $dir, ['PATH' => $dir.'/bin', 'MYSQL_ROOT_PASSWORD' => 'root_pass']);

        expect($result['exit'])->toBe(1)
            ->and($result['stderr'])->toContain('Nothing was changed.')
            ->and(file_exists($dir.'/calls'))->toBeFalse();
    } finally {
        restoreScriptRemoveDir($dir);
    }
});

test('rejects unsupported MySQL and MariaDB backups before calling any client', function (string $engine, string $fixture, bool $dumpAll, string $message) {
    $run = restoreScriptRun($engine, restoreScriptFixture($fixture), $dumpAll);

    expect($run['exit'])->toBe(1)
        ->and($run['stderr'])->toContain($message)
        ->and($run['calls'])->toBe([]);
})->with(['mysql', 'mariadb'])->with([
    'tar with two files' => ['mysql-tar-two', false, 'A tar backup must contain exactly one SQL dump.'],
    'tar with two files, all databases' => ['mysql-tar-two', true, 'A tar backup must contain exactly one SQL dump.'],
    'binary garbage' => ['garbage', false, 'Unsupported backup format.'],
    'binary garbage, all databases' => ['garbage', true, 'Unsupported backup format.'],
    'all-databases dump without dump-all' => ['mysql-cluster', false, 'This backup contains more than one database.'],
    'dump of two databases without dump-all' => ['mysql-two-databases', false, 'This backup contains more than one database.'],
]);

test('restores mongodump archives', function (string $fixture, bool $replaceExisting, bool $gzip) {
    $run = restoreScriptRun('mongodb', restoreScriptFixture($fixture), false, $replaceExisting);

    expect($run['exit'])->toBe(0, $run['stderr']);
    restoreScriptExpectCalls($run['calls'], [['mongorestore', '', [
        '--authenticationDatabase=admin --username root --password mongo_pass',
        '--archive='.$run['backup'],
    ]]]);

    $args = $run['calls'][0]['args'];
    expect(str_contains($args, '--gzip'))->toBe($gzip)
        ->and(str_contains($args, '--drop'))->toBe($replaceExisting);
})->with([
    'plain archive' => ['mongo-archive', false, false],
    'plain archive replacing existing collections' => ['mongo-archive', true, false],
    'gzip archive' => ['mongo-archive-gz', false, true],
    'gzip archive replacing existing collections' => ['mongo-archive-gz', true, true],
]);

test('restores mongodump directories packed as tar', function (string $fixture, bool $gzip) {
    $run = restoreScriptRun('mongodb', restoreScriptFixture($fixture));

    expect($run['exit'])->toBe(0, $run['stderr'])
        ->and($run['calls'])->toHaveCount(1)
        ->and($run['calls'][0]['name'])->toBe('mongorestore')
        ->and($run['calls'][0]['args'])->toMatch('#--dir='.preg_quote($run['dir'], '#').'/tmp\.[^/ ]+/dump$#')
        ->and(str_contains($run['calls'][0]['args'], '--gzip'))->toBe($gzip)
        ->and($run['calls'][0]['args'])->not->toContain('--archive')
        ->and($run['leftovers'])->toBe([]);
})->with([
    'bson files' => ['mongo-dump-tar', false],
    'gzip bson files' => ['mongo-dump-gz-tar', true],
]);

test('restores a mongodump archive wrapped in tar', function () {
    $run = restoreScriptRun('mongodb', restoreScriptFixture('mongo-archive-tar'));

    expect($run['exit'])->toBe(0, $run['stderr'])
        ->and($run['calls'])->toHaveCount(1)
        ->and($run['calls'][0]['name'])->toBe('mongorestore')
        ->and($run['calls'][0]['args'])->toMatch('#--archive='.preg_quote($run['dir'], '#').'/tmp\.[^/ ]+/app\.archive$#')
        ->and($run['calls'][0]['args'])->not->toContain('--gzip')
        ->and($run['leftovers'])->toBe([]);
});

test('rejects unsupported MongoDB backups before calling mongorestore', function (string $fixture, bool $replaceExisting, string $message) {
    $run = restoreScriptRun('mongodb', restoreScriptFixture($fixture), false, $replaceExisting);

    expect($run['exit'])->toBe(1)
        ->and($run['stderr'])->toContain($message)
        ->and($run['calls'])->toBe([]);
})->with([
    'single bson file' => ['mongo-bson', false, 'Unsupported MongoDB backup format'],
    'single bson file replacing existing collections' => ['mongo-bson', true, 'Unsupported MongoDB backup format'],
    'tar without a dump directory' => ['mongo-notes-tar', true, 'The tar backup does not contain a mongodump directory.'],
]);

test('normalize moves plain and gzip backups unchanged', function (string $contents) {
    $result = restoreScriptNormalize($contents);

    expect($result['exit'])->toBe(0, $result['stderr'])
        ->and($result['output'])->toBe($contents)
        ->and($result['sourceExists'])->toBeFalse();
})->with([
    'plain SQL' => ["-- PostgreSQL database dump\nSELECT 1;\n"],
    'gzip SQL' => [gzencode("-- PostgreSQL database dump\nSELECT 1;\n")],
]);

test('normalize decompresses bz2, xz and single-file zip backups', function (string $format, array $tools) {
    foreach ($tools as $tool) {
        if (! restoreScriptHasTool($tool)) {
            $this->markTestSkipped("{$tool} is not installed.");
        }
    }

    $original = "-- MySQL dump 10.13\nCREATE TABLE `items` (`id` int);\n";
    $compressed = match ($format) {
        'bz2' => restoreScriptCompress('bzip2', $original),
        'xz' => restoreScriptCompress('xz', $original),
        'zip' => restoreScriptZip(['backup.sql' => $original]),
    };

    $result = restoreScriptNormalize($compressed);

    expect($result['exit'])->toBe(0, $result['stderr'])
        ->and($result['output'])->toBe($original);
})->with([
    'bz2' => ['bz2', ['bzip2', 'bunzip2']],
    'xz' => ['xz', ['xz', 'unxz']],
    'zip' => ['zip', ['unzip']],
]);

test('normalize rejects backups it cannot unpack', function (string $format, string $tool, string $message) {
    if (! restoreScriptHasTool($tool)) {
        $this->markTestSkipped("{$tool} is not installed.");
    }

    $contents = match ($format) {
        'zip with two files' => restoreScriptZip(['app.sql' => 'SELECT 1;', 'other.sql' => 'SELECT 2;']),
        'corrupt bz2' => 'BZh91AY&SY'.str_repeat("\x00\xffcorrupt", 16),
    };

    $result = restoreScriptNormalize($contents);

    expect($result['exit'])->toBe(1)
        ->and($result['stderr'])->toContain($message);
})->with([
    'zip with two files' => ['zip with two files', 'unzip', 'A zip backup must contain exactly one file.'],
    'corrupt bz2' => ['corrupt bz2', 'bunzip2', 'The bz2 backup cannot be decompressed.'],
]);
