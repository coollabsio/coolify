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

it('uses the selected registry url when extracting upgrade images', function (string $path) {
    $script = file_get_contents(getcwd().'/'.$path);

    expect($script)->toContain('IMAGES=$(REGISTRY_URL=${REGISTRY_URL} LATEST_IMAGE=${LATEST_IMAGE} docker compose --env-file "$ENV_FILE" $COMPOSE_FILES config --images');
})->with([
    'stable upgrade' => 'scripts/upgrade.sh',
    'nightly upgrade' => 'other/nightly/upgrade.sh',
]);

it('persists the selected registry url during upgrades', function (string $path) {
    $script = file_get_contents(getcwd().'/'.$path);

    expect($script)->toContain('set_env_var "REGISTRY_URL" "$REGISTRY_URL"');
})->with([
    'stable upgrade' => 'scripts/upgrade.sh',
    'nightly upgrade' => 'other/nightly/upgrade.sh',
]);

it('persists the target image and runtime version before recreating containers', function (string $path) {
    $script = file_get_contents(getcwd().'/'.$path);
    if ($script === false) {
        throw new RuntimeException("Unable to read {$path}");
    }

    $position = static function (string $needle) use ($script): int {
        $offset = strpos($script, $needle);
        if ($offset === false) {
            throw new RuntimeException("Missing marker: {$needle}");
        }

        return $offset;
    };

    $latestImagePosition = $position('set_env_var "LATEST_IMAGE" "$LATEST_IMAGE"');
    $coolifyVersionPosition = $position('set_env_var "COOLIFY_VERSION" "$LATEST_IMAGE"');
    $imagesPulledPosition = $position('log "All images pulled successfully"');
    $composeUpPosition = $position('docker compose --env-file /data/coolify/source/.env');

    expect($latestImagePosition)->toBeGreaterThan($imagesPulledPosition)
        ->and($coolifyVersionPosition)->toBeGreaterThan($imagesPulledPosition)
        ->and($latestImagePosition)->toBeLessThan($composeUpPosition)
        ->and($coolifyVersionPosition)->toBeLessThan($composeUpPosition);
})->with([
    'stable upgrade' => 'scripts/upgrade.sh',
    'nightly upgrade' => 'other/nightly/upgrade.sh',
]);

it('uses the existing env registry url when old callers do not pass a registry argument', function (string $path) {
    $script = file_get_contents(getcwd().'/'.$path);

    expect($script)
        ->toContain('if [ -n "${3+x}" ]; then')
        ->toContain('REGISTRY_URL="$3"')
        ->toContain('elif [ -f "$ENV_FILE" ] && grep -q "^REGISTRY_URL=" "$ENV_FILE"; then')
        ->toContain("REGISTRY_URL=$(grep \"^REGISTRY_URL=\" \"\$ENV_FILE\" | cut -d '=' -f2- | head -n1)")
        ->toContain('REGISTRY_URL="docker.io"');
})->with([
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
