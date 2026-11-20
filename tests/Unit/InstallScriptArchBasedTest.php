<?php

use Symfony\Component\Process\Process;

it('detects Arch based distributions from ID_LIKE in os-release', function (string $osRelease, bool $isArchBased) {
    $script = file_get_contents(dirname(__DIR__, 2).'/scripts/install.sh');
    preg_match('/^is_arch_based\(\) \{.*?^\}$/ms', $script, $function);
    expect($function)->not->toBeEmpty();

    $osReleaseFile = tempnam(sys_get_temp_dir(), 'coolify-os-release-');
    file_put_contents($osReleaseFile, $osRelease);

    $process = new Process(['bash', '-c', $function[0]."\nis_arch_based \"\$1\"", 'bash', $osReleaseFile]);
    $process->run();
    unlink($osReleaseFile);

    expect($process->isSuccessful())->toBe($isArchBased);
})->with([
    'garuda' => ["NAME=\"Garuda Linux\"\nID=garuda\nID_LIKE=arch\n", true],
    'manjaro arm' => ["NAME=\"Manjaro ARM\"\nID=\"manjaro-arm\"\nID_LIKE=\"manjaro arch\"\n", true],
    'artix without ID_LIKE' => ["NAME=\"Artix Linux\"\nID=artix\n", false],
    'ubuntu' => ["NAME=\"Ubuntu\"\nID=ubuntu\nID_LIKE=debian\n", false],
    'archlike substring' => ["ID=example\nID_LIKE=\"archlinux-ish\"\n", false],
]);
