<?php

use App\Http\Controllers\UploadController;

function invokeHasAllowedExtension(string $name): bool
{
    $method = new ReflectionMethod(UploadController::class, 'hasAllowedExtension');
    $method->setAccessible(true);

    return $method->invoke(null, $name);
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
