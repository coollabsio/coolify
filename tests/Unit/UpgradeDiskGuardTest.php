<?php

it('ships upgrade scripts with valid bash syntax', function (string $path) {
    $process = proc_open(
        ['bash', '-n', getcwd().'/'.$path],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        getcwd()
    );

    expect($process)->toBeResource();

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    expect(proc_close($process), trim($stdout."\n".$stderr))->toBe(0);
})->with([
    'stable upgrade' => 'scripts/upgrade.sh',
    'nightly upgrade' => 'other/nightly/upgrade.sh',
]);

it('aborts the upgrade before pulling images when disk space is too low', function (string $path) {
    $script = file_get_contents(getcwd().'/'.$path);

    // Guard exists with a sane, overridable default and writes an error status the UI can poll.
    expect($script)
        ->toContain('MINIMUM_REQUIRED_DISK_GB="${MINIMUM_REQUIRED_DISK_GB:-3}"')
        ->toContain('write_status "error" "$DISK_MESSAGE"')
        ->toContain('exit 1');

    // A malformed override must fall back to the default rather than disabling the guard.
    expect($script)->toContain("'' | *[!0-9]*) MINIMUM_REQUIRED_DISK_GB=3 ;;");

    // The check must look at the data dir and Docker's storage, using the smaller one.
    expect($script)
        ->toContain('available_mb() { df -Pm "$1" 2>/dev/null | awk \'NR==2 {print $4}\'; }')
        ->toContain('AVAILABLE_MB=$(available_mb /data/coolify)')
        ->toContain("DOCKER_ROOT=\$(docker info --format '{{.DockerRootDir}}' 2>/dev/null)");

    // The guard must run before images are pulled so a full disk never recreates containers.
    $guardPosition = strpos($script, 'Not enough disk space to upgrade safely');
    $pullPosition = strpos($script, 'Step 3/6');

    expect($guardPosition)->not->toBeFalse();
    expect($pullPosition)->not->toBeFalse();
    expect($guardPosition)->toBeLessThan($pullPosition);
})->with([
    'stable upgrade' => 'scripts/upgrade.sh',
    'nightly upgrade' => 'other/nightly/upgrade.sh',
]);

it('backs up the database and records failures before running migrations', function () {
    $command = file_get_contents(__DIR__.'/../../app/Console/Commands/Migration.php');

    expect($command)
        ->toContain('backup_before_migration')
        ->toContain("StandalonePostgresql::whereName('coolify-db')")
        ->toContain('DatabaseBackupJob::dispatchSync($backup)')
        ->toContain('MigrationFailure::record')
        ->toContain('MigrationFailure::clear();');
});

it('surfaces a migration failure through the upgrade status', function () {
    $component = file_get_contents(__DIR__.'/../../app/Livewire/Upgrade.php');

    expect($component)
        ->toContain('MigrationFailure::current()')
        ->toContain("'status' => 'error'")
        ->toContain('Database migration failed: ');
});
