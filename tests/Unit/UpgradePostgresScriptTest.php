<?php

function assertBashSyntaxIsValid(string $path): void
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

it('ships postgres upgrade scripts with valid bash syntax', function () {
    assertBashSyntaxIsValid('scripts/upgrade-postgres.sh');
    assertBashSyntaxIsValid('other/nightly/upgrade-postgres.sh');
});

it('downloads postgres upgrade script during install and upgrade without auto-running it', function (string $path) {
    $script = file_get_contents(getcwd().'/'.$path);

    expect($script)
        ->toContain('upgrade-postgres.sh')
        ->toContain('curl -fsSL -L $CDN/upgrade-postgres.sh -o /data/coolify/source/upgrade-postgres.sh')
        ->toContain('chmod +x')
        ->not->toContain('bash /data/coolify/source/upgrade-postgres.sh');
})->with([
    'stable install' => 'scripts/install.sh',
    'nightly install' => 'other/nightly/install.sh',
    'stable upgrade' => 'scripts/upgrade.sh',
    'nightly upgrade' => 'other/nightly/upgrade.sh',
]);

it('keeps postgres upgrade compose override in future upgrade compose commands', function (string $path) {
    $script = file_get_contents(getcwd().'/'.$path);

    expect($script)
        ->toContain('docker-compose.postgres-upgrade.yml')
        ->toContain('Including PostgreSQL upgrade compose override in image extraction')
        ->toContain('Using PostgreSQL upgrade compose override');
})->with([
    'stable upgrade' => 'scripts/upgrade.sh',
    'nightly upgrade' => 'other/nightly/upgrade.sh',
]);

it('uses postgres 18 compatible mount path in generated override and restore container', function () {
    $script = file_get_contents(getcwd().'/scripts/upgrade-postgres.sh');

    expect($script)
        ->toContain("printf '%s' '/var/lib/postgresql'")
        ->toContain("printf '%s' '/var/lib/postgresql/data'")
        ->toContain('- coolify-db:${mount_path}')
        ->toContain('-v "${TARGET_VOLUME}:${TARGET_MOUNT_PATH}"');
});

it('persists rollback metadata and exposes a rollback command', function () {
    $script = file_get_contents(getcwd().'/scripts/upgrade-postgres.sh');

    expect($script)
        ->toContain('ROLLBACK_FILE="${SOURCE_DIR}/postgres-upgrade-rollback.env"')
        ->toContain('$0 rollback')
        ->toContain('write_rollback_file')
        ->toContain('PREVIOUS_VOLUME=')
        ->toContain('PREVIOUS_IMAGE=')
        ->toContain('PREVIOUS_MOUNT_PATH=')
        ->toContain('rollback_postgres()')
        ->toContain('Rollback completed successfully.');
});

it('detects the active postgres volume instead of assuming coolify-db', function () {
    $script = file_get_contents(getcwd().'/scripts/upgrade-postgres.sh');

    expect($script)
        ->toContain('current_postgres_mount_name()')
        ->toContain('current_postgres_mount_path()')
        ->toContain('current_postgres_image()')
        ->toContain('Current active volume: ${PREVIOUS_VOLUME}')
        ->toContain("Previous volume '")
        ->toContain('will be kept for rollback');
});
