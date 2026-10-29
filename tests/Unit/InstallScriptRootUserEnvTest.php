<?php

use Dotenv\Dotenv;

/**
 * Runs the install script's update_env_var and quote_env_value helpers for the root user
 * credentials against a temporary .env file, then parses that file the way Laravel does.
 *
 * @param  array<string, string>  $credentials
 * @return array<string, string|null>
 */
function writeRootUserCredentialsWithInstallScript(string $scriptPath, array $credentials, string $initialEnv): array
{
    $script = file_get_contents(dirname(__DIR__, 2).'/'.$scriptPath);
    preg_match('/^update_env_var\(\) \{.*?^\}$/ms', $script, $updateEnvVar);
    preg_match('/^quote_env_value\(\) \{.*?^\}$/ms', $script, $quoteEnvValue);
    expect($updateEnvVar)->not->toBeEmpty()
        ->and($quoteEnvValue)->not->toBeEmpty();

    $envFile = tempnam(sys_get_temp_dir(), 'coolify-install-env-');
    file_put_contents($envFile, $initialEnv);

    $commands = array_map(
        fn (string $key, string $value) => "update_env_var {$key} \"\$(quote_env_value ".escapeshellarg($value).')"',
        array_keys($credentials),
        $credentials,
    );
    $process = proc_open(
        ['bash', '-c', implode("\n", ['set -e', 'ENV_FILE='.escapeshellarg($envFile), $updateEnvVar[0], $quoteEnvValue[0], ...$commands])],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0, $output);

    $contents = file_get_contents($envFile);
    unlink($envFile);

    return Dotenv::parse($contents);
}

$installScripts = ['stable install' => 'scripts/install.sh', 'nightly install' => 'other/nightly/install.sh'];

$credentials = [
    'username with a space' => ['ROOT_USERNAME', 'Coolify Lab'],
    'email' => ['ROOT_USER_EMAIL', 'admin@example.com'],
    'password with symbols' => ['ROOT_USER_PASSWORD', 'P@ss w0rd#1|&\\$HOME'],
    'password with quotes' => ['ROOT_USER_PASSWORD', 'it\'s "Quoted" \\ $HOME #1'],
];

it('writes root user credentials that parse back unchanged into an empty variable', function (string $scriptPath, string $key, string $value) {
    $env = writeRootUserCredentialsWithInstallScript($scriptPath, [$key => $value], "APP_NAME=Coolify\n{$key}=\nAPP_ENV=production\n");

    expect($env)->toBe(['APP_NAME' => 'Coolify', $key => $value, 'APP_ENV' => 'production']);
})->with($installScripts)->with($credentials);

it('writes root user credentials that parse back unchanged into a missing variable', function (string $scriptPath, string $key, string $value) {
    $env = writeRootUserCredentialsWithInstallScript($scriptPath, [$key => $value], "APP_NAME=Coolify\n");

    expect($env)->toBe(['APP_NAME' => 'Coolify', $key => $value]);
})->with($installScripts)->with($credentials);

it('keeps root user credentials that are already set', function (string $scriptPath) {
    $env = writeRootUserCredentialsWithInstallScript($scriptPath, ['ROOT_USERNAME' => 'New Name'], "ROOT_USERNAME='Old Name'\n");

    expect($env)->toBe(['ROOT_USERNAME' => 'Old Name']);
})->with($installScripts);
