<?php

use App\Http\Controllers\UploadController;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use App\Support\DatabaseBackupFileValidator;
use App\Support\DatabaseImport\DatabaseImportCommandBuilder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;

function writeScanPayload(string $content, bool $gzip = false): string
{
    $path = tempnam(sys_get_temp_dir(), 'coolify-scan-payload-');
    file_put_contents($path, $gzip ? gzencode($content) : $content);

    return $path;
}

/**
 * Execute the real shell scanner snippet (as it runs inside the container)
 * against a local payload file and return true when the restore is blocked.
 *
 * @param  array<string, string>  $env
 */
function scannerBlocks(string $script, array $env = []): bool
{
    $command = $script;

    if ($env !== []) {
        $exports = [];
        foreach ($env as $name => $value) {
            $exports[] = $name.'='.escapeshellarg($value);
        }
        $command = implode(' ', $exports).'; '.$script;
    }

    return Process::run(['sh', '-c', $command])->exitCode() === 1;
}

function fakePgRestorePath(string $sql, int $listExitCode = 0): string
{
    $dir = sys_get_temp_dir().'/coolify-fake-pg-'.uniqid();
    mkdir($dir);

    $sqlFile = $dir.'/archive.sql';
    file_put_contents($sqlFile, $sql);

    $escapedSqlFile = escapeshellarg($sqlFile);
    file_put_contents($dir.'/pg_restore', <<<SH
#!/bin/sh
if [ "\$1" = '-l' ]; then exit {$listExitCode}; fi
if [ "\$1" = '-f' ] && [ "\$2" = '-' ]; then
  cat {$escapedSqlFile}
  exit 0
fi
exit 1
SH);
    chmod($dir.'/pg_restore', 0755);

    return $dir;
}

function invokeHasAllowedExtension(string $name): bool
{
    $method = new ReflectionMethod(UploadController::class, 'hasAllowedExtension');
    $method->setAccessible(true);

    return $method->invoke(null, $name);
}

function postgresScanScript(string $path): ?string
{
    $database = Mockery::mock(StandalonePostgresql::class);
    $database->shouldReceive('getMorphClass')->andReturn(StandalonePostgresql::class);

    return (new DatabaseImportCommandBuilder)->buildPostgresRestoreScanScript($database, $path);
}

function makeTemporaryUpload(string $name, string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'coolify-upload-test-');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, null, null, true);
}

test('hasAllowedExtension accepts supported extensions', function (string $name) {
    expect(invokeHasAllowedExtension($name))->toBeTrue();
})->with([
    'plain sql' => ['backup.sql'],
    'uppercase sql' => ['BACKUP.SQL'],
    'compound sql.gz' => ['backup.sql.gz'],
    'compound tar.gz' => ['backup.tar.gz'],
    'tgz' => ['archive.tgz'],
    'zip' => ['dump.zip'],
    'tar' => ['dump.tar'],
    'gz' => ['data.gz'],
    'dump' => ['data.dump'],
    'bak' => ['data.bak'],
    'bson' => ['data.bson'],
    'bson.gz' => ['data.bson.gz'],
    'archive' => ['data.archive'],
    'archive.gz' => ['data.archive.gz'],
    'bz2' => ['data.bz2'],
    'xz' => ['data.xz'],
    'sqlite db' => ['app.db'],
    'sqlite' => ['app.sqlite'],
    'sqlite3' => ['app.sqlite3'],
    'gzip sqlite db' => ['app.db.gz'],
    'gzip sqlite' => ['app.sqlite.gz'],
    'gzip sqlite3' => ['app.sqlite3.gz'],
]);

test('hasAllowedExtension rejects unsupported or empty stems', function (string $name) {
    expect(invokeHasAllowedExtension($name))->toBeFalse();
})->with([
    'php' => ['shell.php'],
    'phtml' => ['shell.phtml'],
    'sh' => ['run.sh'],
    'exe' => ['malware.exe'],
    'elf binary no ext' => ['payload'],
    'html' => ['index.html'],
    'bare compound without stem' => ['.sql.gz'],
    'bare extension' => ['.sql'],
    'empty string' => [''],
    'misleading double ext' => ['shell.php.sql-evil'],
]);

test('hasAllowedExtension rejects dangerous double extensions', function (string $name) {
    expect(invokeHasAllowedExtension($name))->toBeFalse();
})->with([
    'php sql' => ['evil.php.sql'],
    'php gzip' => ['evil.php.gz'],
    'shell tar' => ['evil.sh.tar'],
    'php tar gzip' => ['shell.php.tar.gz'],
    'exe zip' => ['cmd.exe.zip'],
    'jsp sql' => ['evil.jsp.sql'],
]);

test('backup validator rejects content that does not match the backup extension', function () {
    $file = makeTemporaryUpload('payload.sql.gz', 'not actually gzip');

    expect(DatabaseBackupFileValidator::isUploadAllowed($file, 10 * 1024 * 1024))->toBeFalse();
});

test('backup validator accepts SQLite database files and their gzip form', function (string $name, string $content) {
    expect(DatabaseBackupFileValidator::isUploadAllowed(makeTemporaryUpload($name, $content), 10 * 1024 * 1024))->toBeTrue();
})->with([
    'db' => ['app.db', "SQLite format 3\0".str_repeat("\0", 84)],
    'sqlite' => ['app.sqlite', "SQLite format 3\0".str_repeat("\0", 84)],
    'sqlite3' => ['app.sqlite3', "SQLite format 3\0".str_repeat("\0", 84)],
    'gzip sqlite' => ['app.sqlite.gz', gzencode("SQLite format 3\0".str_repeat("\0", 84))],
]);

test('backup validator rejects SQLite extensions with content that is not a SQLite database', function (string $name, string $content) {
    expect(DatabaseBackupFileValidator::isUploadAllowed(makeTemporaryUpload($name, $content), 10 * 1024 * 1024))->toBeFalse();
})->with([
    'sql text as db' => ['app.db', "CREATE TABLE users (id integer);\n"],
    'script as sqlite' => ['app.sqlite', "#!/bin/sh\nid\n"],
    'plain sqlite as sqlite.gz' => ['app.sqlite.gz', "SQLite format 3\0".str_repeat("\0", 84)],
]);

test('backup validator accepts valid plain sql and gzip backup content', function () {
    $plainSql = makeTemporaryUpload('backup.sql', "CREATE TABLE users (id integer);\n");
    $gzipSql = makeTemporaryUpload('backup.sql.gz', gzencode("CREATE TABLE users (id integer);\n"));

    expect(DatabaseBackupFileValidator::isUploadAllowed($plainSql, 10 * 1024 * 1024))->toBeTrue()
        ->and(DatabaseBackupFileValidator::isUploadAllowed($gzipSql, 10 * 1024 * 1024))->toBeTrue();
});

test('postgresql backup safety scanner detects program execution payloads', function (string $payload) {
    expect(DatabaseBackupFileValidator::containsPostgresqlProgramExecution($payload))->toBeTrue();
})->with([
    'copy from program' => ["COPY pwned FROM PROGRAM 'id';"],
    'copy to program' => ["COPY pwned TO PROGRAM 'cat > /tmp/out';"],
    'copy with block comment' => ["COPY pwned FROM/**/PROGRAM 'id';"],
    'copy with asterisks in block comment' => ["COPY pwned FROM/*a*b*/PROGRAM 'id';"],
    'copy with nested block comment' => ["COPY pwned FROM /* a /* b */ c */ PROGRAM 'id';"],
    'copy with line comment' => ["COPY pwned FROM--note\nPROGRAM 'id';"],
    'copy with mixed comments' => ["COPY pwned FROM /* -- */\n-- c\r\nPROGRAM 'id';"],
    'psql shell command' => ["\\! id\n"],
    'psql copy program' => ["\\copy pwned from program 'id'\n"],
    'psql shell command after statement' => ["SELECT 1; \\! id\n"],
    'psql write pipe' => ["\\w |id\n"],
    'program inside DO block' => ["DO \$\$ BEGIN COPY t TO PROGRAM 'id'; END \$\$;"],
    'program inside EXECUTE string' => ["DO \$\$ BEGIN EXECUTE 'COPY t TO PROGRAM ''id'''; END \$\$;"],
    'program inside function body' => ["CREATE FUNCTION f() RETURNS void AS 'COPY t TO PROGRAM ''id''' LANGUAGE sql;"],
    'copy with semicolon in string' => ["COPY (SELECT ';') TO PROGRAM 'id';"],
    'program after string holding comment' => ["SELECT '--'; COPY t TO PROGRAM 'id';"],
    'large object import' => ["SELECT lo_import('/etc/passwd');"],
    'alter system' => ["ALTER SYSTEM SET archive_command = 'id';"],
]);

test('postgresql backup safety scanner allows ordinary sql dumps', function () {
    $dump = <<<'SQL'
-- PostgreSQL database dump
CREATE TABLE users (id integer, name text);
COPY users (id, name) FROM stdin;
1	Taylor
\.
SQL;

    expect(DatabaseBackupFileValidator::containsPostgresqlProgramExecution($dump))->toBeFalse();
});

test('postgresql restore commands include a safety check before execution', function () {
    $database = Mockery::mock(StandalonePostgresql::class);
    $database->shouldReceive('getMorphClass')->andReturn(StandalonePostgresql::class);

    $command = (new DatabaseImportCommandBuilder)->buildPostgresSafetyCommand(
        $database,
        'postgres-test',
        '/tmp/restore_test',
    );

    expect($command)
        ->toContain('docker exec postgres-test')
        ->toContain('COPY ... PROGRAM')
        ->toContain('/tmp/restore_test')
        ->toContain('grep -Eiq')
        ->toContain('pg_restore -l');
});

test('file scanner detects program execution payloads inside gzipped backups', function () {
    $gzPayload = writeScanPayload("CREATE TABLE x();\nCOPY x FROM/**/PROGRAM 'id';\n", gzip: true);

    expect(DatabaseBackupFileValidator::fileContainsPostgresqlProgramExecution($gzPayload))->toBeTrue();
});

test('file scanner allows ordinary gzipped dumps', function () {
    $gzClean = writeScanPayload("CREATE TABLE x();\nCOPY x FROM stdin;\n1\\.\n", gzip: true);

    expect(DatabaseBackupFileValidator::fileContainsPostgresqlProgramExecution($gzClean))->toBeFalse();
});

test('file scanner defers custom format archives to pg_restore inspection', function () {
    $archive = writeScanPayload("PGDMP\0binary COPY records FROM PROGRAM payload");

    expect(DatabaseBackupFileValidator::fileContainsPostgresqlProgramExecution($archive))->toBeFalse();
});

test('file scanner defers gzipped custom format archives to pg_restore inspection', function () {
    $archive = writeScanPayload("PGDMP\0binary COPY records FROM PROGRAM payload", gzip: true);

    expect(DatabaseBackupFileValidator::fileContainsPostgresqlProgramExecution($archive))->toBeFalse();
});

test('file scanner allows custom format archives without program execution', function () {
    $archive = writeScanPayload("PGDMP\0binary archive without restore programs");

    expect(DatabaseBackupFileValidator::fileContainsPostgresqlProgramExecution($archive))->toBeFalse();
});

test('postgresql backup safety scanner allows copy from stdin data', function () {
    $dump = "COPY notes FROM stdin;\n1\tTaylor wrote a note\n\\.\n";

    expect(DatabaseBackupFileValidator::containsPostgresqlProgramExecution($dump))->toBeFalse();
});

// Decided trade-off: an unanchored "TO/FROM PROGRAM" match cannot tell a real COPY
// directive from the same two words in a data row, and we keep it unanchored so it
// also catches dynamic SQL. A dump whose data literally contains "from program" or
// "to program" is therefore blocked, with an error that points to the container
// terminal for trusted dumps. This is rare in practice.
test('postgresql backup safety scanner blocks the program token pair even inside table data', function () {
    $dump = "COPY notes FROM stdin;\n1\tcopy files from program storage\n\\.\n";

    expect(DatabaseBackupFileValidator::containsPostgresqlProgramExecution($dump))->toBeTrue();
});

test('postgresql backup safety scanner blocks program execution hidden in dynamic sql', function (string $payload) {
    expect(DatabaseBackupFileValidator::containsPostgresqlProgramExecution($payload))->toBeTrue();
})->with([
    'DO block' => ["DO \$\$ BEGIN COPY t TO PROGRAM 'id'; END \$\$;"],
    'EXECUTE string' => ["DO \$\$ BEGIN EXECUTE 'COPY t TO PROGRAM ''id'''; END \$\$;"],
    'function body' => ["CREATE FUNCTION f() RETURNS void AS 'COPY t TO PROGRAM ''id''' LANGUAGE sql;"],
    'semicolon in string' => ["COPY (SELECT ';') TO PROGRAM 'id';"],
    'string holding comment marker' => ["SELECT '--'; COPY t TO PROGRAM 'id';"],
    'lo_import' => ["SELECT lo_import('/etc/passwd');"],
    'alter system' => ["ALTER SYSTEM SET archive_command = 'id';"],
    'mid-statement psql shell' => ["SELECT 1; \\! id\n"],
]);

test('backup validator rejects plaintext .dump containing program execution', function () {
    $file = makeTemporaryUpload('evil.dump', "COPY x FROM PROGRAM 'id';\n");

    expect(DatabaseBackupFileValidator::isUploadAllowed($file, 10 * 1024 * 1024))->toBeFalse();
});

test('remote postgresql scanner blocks bypass payloads', function (string $content, bool $gzip) {
    $payload = writeScanPayload($content, $gzip);
    $script = postgresScanScript($payload);

    expect(scannerBlocks($script))->toBeTrue();
})->with([
    'psql shell escape' => ["\\! id\n", false],
    'copy from program' => ["COPY x FROM PROGRAM 'id';\n", false],
    'copy with block comment' => ["COPY x FROM/**/PROGRAM 'id';\n", false],
    'copy with asterisks in block comment' => ["COPY x FROM/*a*b*/PROGRAM 'id';\n", false],
    'copy with nested block comment' => ["COPY x FROM /* a /* b */ c */ PROGRAM 'id';\n", false],
    'copy with line comment' => ["COPY x FROM--note\nPROGRAM 'id';\n", false],
    'copy with marker char in line comment' => ["COPY x FROM -- a~b\nPROGRAM 'id';\n", false],
    'copy with mixed comments' => ["COPY x FROM /* -- */\n-- c\r\nPROGRAM 'id';\n", false],
    'copy with block comment across lines' => ["COPY x FROM /* a\n-- */ PROGRAM 'id';\n", true],
    'copy split across lines' => ["COPY x FROM\nPROGRAM 'id';\n", false],
    'copy to program' => ["COPY x TO PROGRAM 'cat > /tmp/x';\n", false],
    'psql pipe redirect' => ["\\o | id\n", false],
    'psql query pipe redirect' => ["\\g | id\n", false],
    'psql write pipe redirect' => ["\\w |id\n", false],
    'psql shell after statement' => ["SELECT 1; \\! id\n", false],
    'gzipped comment bypass' => ["COPY x FROM/**/PROGRAM 'id';\n", true],
    'custom format archive' => ["PGDMP\0binary COPY records FROM PROGRAM payload", false],
    'custom format gzip archive' => ["PGDMP\0binary COPY records FROM PROGRAM payload", true],
    // Bypasses of the former (^|;) ... [^;]* COPY-only pattern.
    'program inside DO block' => ["DO \$\$ BEGIN COPY t TO PROGRAM 'id'; END \$\$;\n", false],
    'program inside EXECUTE string' => ["DO \$\$ BEGIN EXECUTE 'COPY t TO PROGRAM ''id'''; END \$\$;\n", false],
    'program inside function body' => ["CREATE FUNCTION f() RETURNS void AS 'COPY t TO PROGRAM ''id''' LANGUAGE sql;\n", false],
    'copy with semicolon in string' => ["COPY (SELECT ';') TO PROGRAM 'id';\n", false],
    'program after string holding comment' => ["SELECT '--'; COPY t TO PROGRAM 'id';\n", false],
    'large object import' => ["SELECT lo_import('/etc/passwd');\n", false],
    'alter system' => ["ALTER SYSTEM SET archive_command = 'id';\n", false],
    'commented out payload is now blocked' => ["-- COPY x FROM PROGRAM 'id'\nSELECT 1;\n", false],
    'program words in table data are blocked' => ["COPY notes FROM stdin;\n1\tcopy files from program storage\n\\.\n", false],
]);

test('remote postgresql scanner allows legitimate restores', function (string $content, bool $gzip) {
    $payload = writeScanPayload($content, $gzip);
    $script = postgresScanScript($payload);

    expect(scannerBlocks($script))->toBeFalse();
})->with([
    'copy from stdin' => ["COPY users FROM stdin;\n1\tTaylor\n\\.\n", false],
    'plain select' => ["SELECT * FROM users;\n", false],
    'gzipped clean dump' => ["CREATE TABLE users (id int);\n", true],
    'copy keyword without program' => ["COPY users (id, name) FROM stdin;\n1\tTaylor\n\\.\n", false],
    'program word alone in data' => ["COPY notes FROM stdin;\n1\tfinished the program today\n\\.\n", false],
    'comments between other words' => ["SELECT a FROM /* x */ t -- note\nWHERE b ~ 'program';\n", false],
]);

test('the postgres safety command survives the non-root sudo parser', function (string $content, bool $blocked) {
    $payload = writeScanPayload($content);

    $database = Mockery::mock(StandalonePostgresql::class);
    $database->shouldReceive('getMorphClass')->andReturn(StandalonePostgresql::class);

    $server = Mockery::mock(Server::class)->makePartial();
    $server->user = 'ubuntu';

    $command = (new DatabaseImportCommandBuilder)->buildPostgresSafetyCommand($database, 'scan-container', $payload);
    $elevated = parseCommandsByLineForSudo(collect([$command]), $server)[0];

    expect($elevated)->toStartWith('sudo bash -c ')
        ->and($elevated)->not->toContain(' | sudo ');

    // Strip the host elevation and the container indirection so the embedded scan runs
    // locally against the payload file; this proves the parser kept the script quoting intact.
    $local = str_replace(['sudo bash -c', 'docker exec scan-container sh -c'], ['bash -c', 'sh -c'], $elevated);

    expect(Process::run(['sh', '-c', $local])->exitCode() === 1)->toBe($blocked);
})->with([
    'blocks program payload' => ["COPY t TO PROGRAM 'id';\n", true],
    'blocks dynamic sql payload' => ["DO \$\$ BEGIN COPY t TO PROGRAM 'id'; END \$\$;\n", true],
    'allows a clean dump' => ["COPY users FROM stdin;\n1\tTaylor\n\\.\n", false],
]);

test('remote postgresql scanner inspects custom archives instead of skipping them', function () {
    $safeArchive = writeScanPayload("PGDMP\0binary archive");
    $maliciousSql = "COPY x FROM PROGRAM 'id';\n";
    $safeSql = "CREATE TABLE users (id integer);\nCOPY users FROM stdin;\n1\tTaylor\n\\.\n";

    $maliciousPath = fakePgRestorePath($maliciousSql);
    $safePath = fakePgRestorePath($safeSql);
    $unreadablePath = fakePgRestorePath($safeSql, listExitCode: 1);
    $path = getenv('PATH') ?: '/usr/bin:/bin';

    expect(scannerBlocks(postgresScanScript($safeArchive), ['PATH' => $maliciousPath.':'.$path]))->toBeTrue()
        ->and(scannerBlocks(postgresScanScript($safeArchive), ['PATH' => $safePath.':'.$path]))->toBeFalse()
        ->and(scannerBlocks(postgresScanScript($safeArchive), ['PATH' => $unreadablePath.':'.$path]))->toBeTrue()
        ->and(scannerBlocks(postgresScanScript($safeArchive)))->toBeTrue();
});

test('MAX_BYTES constant is 10 GiB', function () {
    $constant = (new ReflectionClass(UploadController::class))->getConstant('MAX_BYTES');
    expect($constant)->toBe(10 * 1024 * 1024 * 1024);
});

test('ALLOWED_EXTENSIONS does not include executable formats', function () {
    $constant = (new ReflectionClass(UploadController::class))->getConstant('ALLOWED_EXTENSIONS');
    expect($constant)->toBeArray();

    $forbidden = ['php', 'phtml', 'php5', 'sh', 'bash', 'exe', 'js', 'html', 'htm', 'pl', 'py'];
    foreach ($forbidden as $bad) {
        expect($constant)->not->toContain($bad);
    }
});

// psql meta-commands can follow SQL on the same line, so they are matched anywhere after an
// odd backslash run. COPY data writes a literal backslash as "\\" and uses \n, \b, \v and \N,
// which psql does not know as commands, so ordinary dumps must still pass.
dataset('psql shell meta-commands', [
    'shell escape after sql without semicolon' => ["SELECT 1 \\! id\n"],
    'output long name piped' => ["\\out |id\nSELECT 1;\n"],
    'output piped after sql' => ["SELECT 1 \\o |id\n"],
    'query piped with options' => ["SELECT 1 \\g (format=csv) |id\n"],
    'expanded query piped' => ["SELECT 1 \\gx |id\n"],
    'write long name piped' => ["\\write |id\n"],
    'copy program after sql' => ["SELECT 1 \\copy t from program 'id'\n"],
    'backquote in set' => ["\\set x `id`\n"],
    'backquote in short command after sql' => ["SELECT 1 \\t `id`\n"],
    'include file' => ["\\i /tmp/generated.sql\n"],
    'include relative file' => ["\\include_relative generated.sql\n"],
    'editor environment' => ["\\setenv PSQL_EDITOR id\n"],
    'editor' => ["\\e\n"],
    'shell escape after escaped backslash' => ["SELECT 1 \\\\\\! id\n"],
]);

dataset('psql safe dump content', [
    'escaped backslash before bang in copy data' => ["COPY notes FROM stdin;\n1\tC:\\\\! dir\n\\.\n"],
    'copy escapes before backquotes' => ["COPY notes FROM stdin;\n1\tline\\n `code` \\b `x` \\v `y`\t\\N\n\\.\n"],
    'copy hex and octal escapes' => ["COPY notes FROM stdin;\n1\t\\x41 \\101 |pipe\n\\.\n"],
    'pg_dumpall connect' => ["\\connect app\nSELECT 1;\n"],
    'pg_dump restrict keys' => ["\\restrict abc123\nSELECT 1;\n\\unrestrict abc123\n"],
]);

test('postgresql backup safety scanner blocks psql shell meta-commands anywhere on a line', function (string $payload) {
    expect(DatabaseBackupFileValidator::containsPostgresqlProgramExecution($payload))->toBeTrue();
})->with('psql shell meta-commands');

test('postgresql backup safety scanner allows backslash sequences of ordinary dumps', function (string $payload) {
    expect(DatabaseBackupFileValidator::containsPostgresqlProgramExecution($payload))->toBeFalse();
})->with('psql safe dump content');

test('remote postgresql scanner blocks psql shell meta-commands anywhere on a line', function (string $payload) {
    expect(scannerBlocks(postgresScanScript(writeScanPayload($payload))))->toBeTrue();
})->with('psql shell meta-commands');

test('remote postgresql scanner allows backslash sequences of ordinary dumps', function (string $payload) {
    expect(scannerBlocks(postgresScanScript(writeScanPayload($payload))))->toBeFalse();
})->with('psql safe dump content');
