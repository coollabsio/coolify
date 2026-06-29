<?php

use App\Http\Controllers\UploadController;
use App\Livewire\Project\Database\ImportForm;
use App\Models\StandalonePostgresql;
use App\Support\DatabaseBackupFileValidator;
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
 */
function scannerBlocks(string $script): bool
{
    return Process::run(['sh', '-c', $script])->exitCode() === 1;
}

function invokeHasAllowedExtension(string $name): bool
{
    $method = new ReflectionMethod(UploadController::class, 'hasAllowedExtension');
    $method->setAccessible(true);

    return $method->invoke(null, $name);
}

function backupValidationImportFormWithResource(string $modelClass): ImportForm
{
    $component = new class extends ImportForm
    {
        public $resource;
    };

    $database = Mockery::mock($modelClass);
    $database->shouldReceive('getMorphClass')->andReturn($modelClass);
    $component->resource = $database;

    return $component;
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
    'psql shell command' => ["\\! id\n"],
    'psql copy program' => ["\\copy pwned from program 'id'\n"],
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
    $component = new class extends ImportForm
    {
        public function __get($property)
        {
            if ($property === 'resource') {
                return new class
                {
                    public function getMorphClass(): string
                    {
                        return StandalonePostgresql::class;
                    }
                };
            }

            return parent::__get($property);
        }
    };
    $component->container = 'postgres-test';

    $command = $component->buildRestoreSafetyCheckCommand('/tmp/restore_test');

    expect($command)
        ->toContain('docker exec postgres-test')
        ->toContain('COPY ... PROGRAM')
        ->toContain('/tmp/restore_test')
        ->toContain('grep -Eiq');
});

test('non postgresql restore commands do not include a safety check', function () {
    $component = backupValidationImportFormWithResource('App\Models\StandaloneMysql');
    $component->container = 'mysql-test';

    expect($component->buildRestoreSafetyCheckCommand('/tmp/restore_test'))->toBeNull();
});

test('file scanner detects program execution payloads inside gzipped backups', function () {
    $gzPayload = writeScanPayload("CREATE TABLE x();\nCOPY x FROM/**/PROGRAM 'id';\n", gzip: true);

    expect(DatabaseBackupFileValidator::fileContainsPostgresqlProgramExecution($gzPayload))->toBeTrue();
});

test('file scanner allows ordinary gzipped dumps', function () {
    $gzClean = writeScanPayload("CREATE TABLE x();\nCOPY x FROM stdin;\n1\\.\n", gzip: true);

    expect(DatabaseBackupFileValidator::fileContainsPostgresqlProgramExecution($gzClean))->toBeFalse();
});

test('backup validator rejects plaintext .dump containing program execution', function () {
    $file = makeTemporaryUpload('evil.dump', "COPY x FROM PROGRAM 'id';\n");

    expect(DatabaseBackupFileValidator::isUploadAllowed($file, 10 * 1024 * 1024))->toBeFalse();
});

test('remote postgresql scanner blocks bypass payloads', function (string $content, bool $gzip) {
    $component = backupValidationImportFormWithResource(StandalonePostgresql::class);
    $component->container = 'postgres-test';

    $payload = writeScanPayload($content, $gzip);
    $script = $component->buildPostgresRestoreScanScript($payload);

    expect(scannerBlocks($script))->toBeTrue();
})->with([
    'psql shell escape' => ["\\! id\n", false],
    'copy from program' => ["COPY x FROM PROGRAM 'id';\n", false],
    'copy with block comment' => ["COPY x FROM/**/PROGRAM 'id';\n", false],
    'copy split across lines' => ["COPY x FROM\nPROGRAM 'id';\n", false],
    'copy to program' => ["COPY x TO PROGRAM 'cat > /tmp/x';\n", false],
    'psql pipe redirect' => ["\\o | id\n", false],
    'gzipped comment bypass' => ["COPY x FROM/**/PROGRAM 'id';\n", true],
]);

test('remote postgresql scanner allows legitimate restores', function (string $content, bool $gzip) {
    $component = backupValidationImportFormWithResource(StandalonePostgresql::class);
    $component->container = 'postgres-test';

    $payload = writeScanPayload($content, $gzip);
    $script = $component->buildPostgresRestoreScanScript($payload);

    expect(scannerBlocks($script))->toBeFalse();
})->with([
    'commented out payload' => ["-- COPY x FROM PROGRAM 'id'\nSELECT 1;\n", false],
    'copy from stdin' => ["COPY users FROM stdin;\n1\tTaylor\n\\.\n", false],
    'plain select' => ["SELECT * FROM users;\n", false],
    'gzipped clean dump' => ["CREATE TABLE users (id int);\n", true],
]);

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
