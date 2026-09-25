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
 * format is rejected.
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
 * @return array{exit: int, stdout: string, stderr: string, calls: list<array{name: string, args: string, header: string}>, dir: string, backup: string, leftovers: list<string>}
 */
function restoreScriptRun(string $engine, string $contents, bool $dumpAll = false, bool $replaceExisting = false): array
{
    $dir = restoreScriptTempDir();

    try {
        $log = $dir.'/calls.log';
        file_put_contents($log, '');
        mkdir($dir.'/bin');

        foreach (['pg_restore', 'psql', 'dropdb', 'createdb', 'mysql', 'mariadb', 'mongorestore'] as $client) {
            $escapedLog = escapeshellarg($log);
            file_put_contents($dir.'/bin/'.$client, <<<SH
#!/bin/sh
header=''
if [ ! -t 0 ]; then
    header=\$(head -c 5)
    cat >/dev/null
fi
printf '%s|%s|%s\\n' '{$client}' "\$*" "\$header" >> {$escapedLog}
exit 0
SH);
            chmod($dir.'/bin/'.$client, 0755);
        }

        $backup = $dir.'/backup file';
        file_put_contents($backup, $contents);

        $script = (new DatabaseImportCommandBuilder)->buildRestoreCommand(restoreScriptResource($engine), $backup, $dumpAll, $replaceExisting);

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
        $calls = array_map(function (string $line): array {
            [$name, $args, $header] = array_pad(explode('|', $line, 3), 3, '');

            return ['name' => $name, 'args' => $args, 'header' => $header];
        }, $lines);

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

test('restores single PostgreSQL archives with pg_restore and SQL with psql', function (string $fixture, bool $replaceExisting, array $expectedCalls) {
    $run = restoreScriptRun('postgresql', restoreScriptFixture($fixture), false, $replaceExisting);

    expect($run['exit'])->toBe(0, $run['stderr']);
    restoreScriptExpectCalls($run['calls'], $expectedCalls);
})->with([
    'custom archive' => ['pg-custom', false, [['pg_restore', 'PGDMP', ['--exit-on-error -U postgres -d app']]]],
    'custom archive replacing existing objects' => ['pg-custom', true, [['pg_restore', 'PGDMP', ['--exit-on-error --clean --if-exists -U postgres -d app']]]],
    'gzip custom archive' => ['pg-custom-gz', false, [['pg_restore', 'PGDMP', ['--exit-on-error -U postgres -d app']]]],
    'gzip custom archive replacing existing objects' => ['pg-custom-gz', true, [['pg_restore', 'PGDMP', ['--exit-on-error --clean --if-exists -U postgres -d app']]]],
    'tar archive' => ['pg-tar', false, [['pg_restore', 'toc.d', ['--exit-on-error -U postgres -d app']]]],
    'gzip tar archive' => ['pg-tar-gz', false, [['pg_restore', 'toc.d', ['--exit-on-error -U postgres -d app']]]],
    'plain SQL' => ['pg-sql', false, [['psql', '-- Po', ['-v ON_ERROR_STOP=1 -U postgres -d app']]]],
    'gzip SQL' => ['pg-sql-gz', false, [['psql', '-- Po', ['-v ON_ERROR_STOP=1 -U postgres -d app']]]],
    // SQL cannot replace single objects, so replacing recreates the target database first.
    'plain SQL replacing existing objects' => ['pg-sql', true, [
        ['psql', 'SELEC', ['-v db=app -U postgres -d template1']],
        ['dropdb', '', ['--maintenance-db=template1 -U postgres --if-exists app']],
        ['createdb', '', ['-U postgres app']],
        ['psql', '-- Po', ['-v ON_ERROR_STOP=1 -U postgres -d app']],
    ]],
    'gzip SQL replacing existing objects' => ['pg-sql-gz', true, [
        ['psql', 'SELEC', ['-v db=app -U postgres -d template1']],
        ['dropdb', '', ['--maintenance-db=template1 -U postgres --if-exists app']],
        ['createdb', '', ['-U postgres app']],
        ['psql', '-- Po', ['-v ON_ERROR_STOP=1 -U postgres -d app']],
    ]],
]);

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
