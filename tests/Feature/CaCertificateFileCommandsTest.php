<?php

use App\Helpers\SslHelper;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->baseConfigPath = sys_get_temp_dir().'/coolify-ca-test-'.uniqid();
    $this->caFile = $this->baseConfigPath.'/ssl/coolify-ca.crt';
    config()->set('constants.coolify.base_config_path', $this->baseConfigPath);
});

afterEach(function () {
    File::deleteDirectory($this->baseConfigPath);
});

function runCaCertificateFileCommands(string $certificate): void
{
    // chown needs root, so the script continues when it fails.
    $process = Process::fromShellCommandline(implode("\n", SslHelper::caCertificateFileCommands($certificate)));
    $process->run();
}

test('rewrites the CA file in place so existing bind mounts see the new certificate (#6081)', function () {
    File::ensureDirectoryExists(dirname($this->caFile));
    File::put($this->caFile, "old certificate\n");
    chmod($this->caFile, 0644);
    $inodeBefore = fileinode($this->caFile);

    runCaCertificateFileCommands("new certificate\n");

    clearstatcache();
    expect(fileinode($this->caFile))->toBe($inodeBefore)
        ->and(File::get($this->caFile))->toBe("new certificate\n")
        ->and(fileperms($this->caFile) & 0777)->toBe(0644);
});

test('replaces a CA path that Docker created as a directory with the certificate file', function () {
    File::ensureDirectoryExists($this->caFile);

    runCaCertificateFileCommands("certificate\n");

    expect(is_file($this->caFile))->toBeTrue()
        ->and(File::get($this->caFile))->toBe("certificate\n")
        ->and(fileperms($this->caFile) & 0777)->toBe(0644);
});
