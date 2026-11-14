<?php

use App\Helpers\SshMultiplexingHelper;

/**
 * Captured from OpenSSH 10.0p2 with StrictHostKeyChecking=no and a wrong key in the known_hosts file.
 * ssh ends most lines with \r\n, but not the fingerprint intro line.
 */
function capturedHostKeyChangedWarning(): string
{
    return "@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@\r\n"
        ."@    WARNING: REMOTE HOST IDENTIFICATION HAS CHANGED!     @\r\n"
        ."@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@\r\n"
        ."IT IS POSSIBLE THAT SOMEONE IS DOING SOMETHING NASTY!\r\n"
        ."Someone could be eavesdropping on you right now (man-in-the-middle attack)!\r\n"
        ."It is also possible that a host key has just been changed.\r\n"
        ."The fingerprint for the ED25519 key sent by the remote host is\n"
        ."SHA256:HwD7uwuyJ8Nns/3ORRZ1nTRNABjxDxOjt16LpPM22kE.\r\n"
        ."Please contact your system administrator.\r\n"
        ."Add correct host key in /var/www/html/storage/app/ssh/known_hosts/known_hosts_abc_123 to get rid of this message.\r\n"
        ."Offending ED25519 key in /var/www/html/storage/app/ssh/known_hosts/known_hosts_abc_123:1\r\n"
        ."  remove with:\r\n"
        ."  ssh-keygen -f '/var/www/html/storage/app/ssh/known_hosts/known_hosts_abc_123' -R 'coolify-abc'\r\n"
        ."Password authentication is disabled to avoid man-in-the-middle attacks.\r\n"
        ."Keyboard-interactive authentication is disabled to avoid man-in-the-middle attacks.\r\n";
}

it('removes the changed host key warning and keeps the other stderr lines', function () {
    $stderr = capturedHostKeyChangedWarning()."fatal: not a git repository\nerror: build failed\n";

    expect(SshMultiplexingHelper::stripHostKeyChangedWarning($stderr))
        ->toBe("fatal: not a git repository\nerror: build failed\n");
});

it('keeps the strict checking failure lines after the warning', function () {
    $stderr = capturedHostKeyChangedWarning()
        ."Host key for coolify-abc has changed and you have requested strict checking.\r\n"
        ."Host key verification failed.\r\n";

    $stripped = SshMultiplexingHelper::stripHostKeyChangedWarning($stderr);

    expect($stripped)->toBe("Host key for coolify-abc has changed and you have requested strict checking.\r\nHost key verification failed.\r\n")
        ->and(SshMultiplexingHelper::isHostKeyMismatch($stderr))->toBeTrue();
});

it('keeps a changed host key warning that a program on the server prints later', function () {
    $stderr = "Cloning into 'app'...\n".capturedHostKeyChangedWarning();

    expect(SshMultiplexingHelper::stripHostKeyChangedWarning($stderr))->toBe($stderr);
});

it('removes the warning from stderr that arrives in chunks of any size', function (int $chunkSize) {
    $stderr = capturedHostKeyChangedWarning()."warning: remote line\n";
    $held = '';
    $passed = '';
    foreach (str_split($stderr, $chunkSize) as $chunk) {
        $passed .= SshMultiplexingHelper::filterHostKeyChangedWarning($held, $chunk);
    }
    $passed .= SshMultiplexingHelper::filterHostKeyChangedWarning($held, '', final: true);

    expect($passed)->toBe("warning: remote line\n")
        ->and($held)->toBeNull();
})->with([1, 7, 64, 100000]);

it('passes other stderr on at once', function () {
    $held = '';

    expect(SshMultiplexingHelper::filterHostKeyChangedWarning($held, "error: build failed\n"))->toBe("error: build failed\n")
        ->and(SshMultiplexingHelper::filterHostKeyChangedWarning($held, "@ not a warning\n"))->toBe("@ not a warning\n");
});
