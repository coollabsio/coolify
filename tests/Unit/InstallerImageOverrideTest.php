<?php

function assertBashSyntaxIsValidForImageOverride(string $path): void
{
    $process = proc_open(
        ['bash', '-n', getcwd().'/'.$path],
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        getcwd()
    );

    expect($process)->toBeResource();

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    expect($exitCode, trim($stdout."\n".$stderr))->toBe(0);
}

it('supports complete internal image overrides in linux compose manifests', function (string $path) {
    $compose = file_get_contents(getcwd().'/'.$path);

    expect($compose)
        ->toContain('image: "${POSTGRES_IMAGE:-postgres:15-alpine}"')
        ->toContain('image: "${REDIS_IMAGE:-redis:7-alpine}"');
})->with([
    'stable compose' => 'docker-compose.yml',
    'nightly compose' => 'other/nightly/docker-compose.yml',
]);

it('ships blank internal image overrides in production environment templates', function (string $path) {
    $environment = file_get_contents(getcwd().'/'.$path);

    expect($environment)
        ->toContain("POSTGRES_IMAGE=\n")
        ->toContain("REDIS_IMAGE=\n");
})->with([
    'stable environment' => '.env.production',
    'nightly environment' => 'other/nightly/.env.production',
]);

it('persists explicit non-empty internal image overrides during install', function (string $path) {
    $installer = file_get_contents(getcwd().'/'.$path);

    expect($installer)
        ->toContain('## POSTGRES_IMAGE - Full PostgreSQL image override')
        ->toContain('## REDIS_IMAGE - Full Redis image override')
        ->toContain('if [ -n "$POSTGRES_IMAGE" ]; then')
        ->toContain('set_image_override "POSTGRES_IMAGE" "$POSTGRES_IMAGE"')
        ->toContain('if [ -n "$REDIS_IMAGE" ]; then')
        ->toContain('set_image_override "REDIS_IMAGE" "$REDIS_IMAGE"')
        ->toContain('if [[ ! "$value" =~ ^[A-Za-z0-9][A-Za-z0-9._/@:-]*$ ]]; then')
        ->not->toContain('update_env_var "POSTGRES_IMAGE"')
        ->not->toContain('update_env_var "REDIS_IMAGE"');
})->with([
    'stable installer' => 'scripts/install.sh',
    'nightly installer' => 'other/nightly/install.sh',
]);

it('ships installers with valid bash syntax', function (string $path) {
    assertBashSyntaxIsValidForImageOverride($path);
})->with([
    'stable installer' => 'scripts/install.sh',
    'nightly installer' => 'other/nightly/install.sh',
]);

it('preserves internal image overrides during upgrades', function (string $path) {
    $upgrade = file_get_contents(getcwd().'/'.$path);

    expect($upgrade)
        ->toContain("awk -F '=' '!seen[\$1]++' \"\$ENV_FILE\" /data/coolify/source/.env.production")
        ->not->toContain('set_env_var "POSTGRES_IMAGE"')
        ->not->toContain('set_env_var "REDIS_IMAGE"');
})->with([
    'stable upgrade' => 'scripts/upgrade.sh',
    'nightly upgrade' => 'other/nightly/upgrade.sh',
]);
