<?php

use Dotenv\Dotenv;

/**
 * Runs the upgrade script's quote_root_user_env_values helper against a temporary .env file
 * and returns the file contents after the repair.
 */
function repairRootUserEnvWithUpgradeScript(string $scriptPath, string $initialEnv): string
{
    $script = file_get_contents(dirname(__DIR__, 2).'/'.$scriptPath);
    preg_match('/^quote_env_value\(\) \{.*?^\}$/ms', $script, $quoteEnvValue);
    preg_match('/^quote_root_user_env_values\(\) \{.*?^\}$/ms', $script, $quoteRootUserEnvValues);
    expect($quoteEnvValue)->not->toBeEmpty()
        ->and($quoteRootUserEnvValues)->not->toBeEmpty();

    $envFile = tempnam(sys_get_temp_dir(), 'coolify-upgrade-env-');
    file_put_contents($envFile, $initialEnv);

    $process = proc_open(
        ['bash', '-c', implode("\n", ['set -e', 'log() { :; }', 'ENV_FILE='.escapeshellarg($envFile), $quoteEnvValue[0], $quoteRootUserEnvValues[0], 'quote_root_user_env_values'])],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0, $output);

    $contents = file_get_contents($envFile);
    unlink($envFile);

    return $contents;
}

$upgradeScripts = ['stable upgrade' => 'scripts/upgrade.sh', 'nightly upgrade' => 'other/nightly/upgrade.sh'];

it('quotes unquoted root user credentials with spaces so that .env parses again', function (string $scriptPath) {
    $env = repairRootUserEnvWithUpgradeScript($scriptPath, implode("\n", [
        'APP_NAME=Coolify',
        'ROOT_USERNAME=Jane Doe',
        'ROOT_USER_EMAIL=admin@example.com',
        "ROOT_USER_PASSWORD=it's a P@ss|&\\ \$HOME",
        'APP_ENV=production',
        '',
    ]));

    expect(Dotenv::parse($env))->toBe([
        'APP_NAME' => 'Coolify',
        'ROOT_USERNAME' => 'Jane Doe',
        'ROOT_USER_EMAIL' => 'admin@example.com',
        'ROOT_USER_PASSWORD' => "it's a P@ss|&\\ \$HOME",
        'APP_ENV' => 'production',
    ]);
})->with($upgradeScripts);

it('keeps root user credentials that already parse unchanged', function (string $scriptPath) {
    $initialEnv = implode("\n", [
        'ROOT_USERNAME="Jane Doe"',
        "ROOT_USER_EMAIL='admin@example.com'",
        'ROOT_USER_PASSWORD=',
        'APP_NAME=My Coolify',
        '',
    ]);

    expect(repairRootUserEnvWithUpgradeScript($scriptPath, $initialEnv))->toBe($initialEnv);
})->with($upgradeScripts);

it('keeps root user credentials that only have whitespace around the value unchanged', function (string $scriptPath) {
    $initialEnv = "ROOT_USER_EMAIL=admin@example.com\r\nROOT_USERNAME= Jane\r\nROOT_USER_PASSWORD=Secret1!  \r\nAPP_ENV=production\r\n";

    expect(repairRootUserEnvWithUpgradeScript($scriptPath, $initialEnv))->toBe($initialEnv);
})->with($upgradeScripts);

it('quotes a root username with spaces in a file with CRLF line endings', function (string $scriptPath) {
    $env = repairRootUserEnvWithUpgradeScript($scriptPath, "ROOT_USERNAME=Jane Doe\r\nAPP_ENV=production\r\n");

    expect(Dotenv::parse($env))->toBe(['ROOT_USERNAME' => 'Jane Doe', 'APP_ENV' => 'production']);
})->with($upgradeScripts);
