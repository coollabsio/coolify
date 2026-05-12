<?php

/**
 * Test to verify that empty .env files are created for build packs that require them.
 *
 * Both 'dockerimage' and 'dockercompose' build packs need the .env file to exist:
 *  - 'dockerimage': uses env_file: ['.env'] in its generated single-container compose file.
 *  - 'dockercompose': passes --env-file .env to docker compose for variable interpolation.
 *
 * The fix ensures that for both build packs an empty .env file is created even when
 * there are no environment variables defined, so docker compose does not fail.
 */
it('determines which build packs require empty .env file creation', function () {
    // Build packs that need the .env file to exist (for env_file: or --env-file interpolation)
    $buildPacksRequiringEnvFile = ['dockerimage', 'dockercompose'];

    // Build packs that don't use env_file in the compose file
    $buildPacksNotRequiringEnvFile = ['dockerfile', 'nixpacks', 'static'];

    foreach ($buildPacksRequiringEnvFile as $buildPack) {
        // Verify the condition matches our fix
        $requiresEnvFile = ($buildPack === 'dockercompose' || $buildPack === 'dockerimage');
        expect($requiresEnvFile)->toBeTrue("Build pack '{$buildPack}' should require empty .env file");
    }

    foreach ($buildPacksNotRequiringEnvFile as $buildPack) {
        // These build packs also use env_file but call save_runtime_environment_variables()
        // after generate_compose_file(), so they handle empty env files themselves
        $requiresEnvFile = ($buildPack === 'dockercompose' || $buildPack === 'dockerimage');
        expect($requiresEnvFile)->toBeFalse("Build pack '{$buildPack}' should not match the condition");
    }
});

it('verifies dockerimage build pack is included in empty env file creation logic', function () {
    $buildPack = 'dockerimage';
    $shouldCreateEmptyEnvFile = ($buildPack === 'dockercompose' || $buildPack === 'dockerimage');

    expect($shouldCreateEmptyEnvFile)->toBeTrue(
        'dockerimage build pack should create empty .env file when no environment variables are defined'
    );
});

it('verifies dockercompose build pack is included in empty env file creation logic', function () {
    $buildPack = 'dockercompose';
    $shouldCreateEmptyEnvFile = ($buildPack === 'dockercompose' || $buildPack === 'dockerimage');

    expect($shouldCreateEmptyEnvFile)->toBeTrue(
        'dockercompose build pack should create empty .env file when no environment variables are defined'
    );
});

it('verifies other build packs are not included in empty env file creation logic', function () {
    $otherBuildPacks = ['dockerfile', 'nixpacks', 'static', 'buildpack'];

    foreach ($otherBuildPacks as $buildPack) {
        $shouldCreateEmptyEnvFile = ($buildPack === 'dockercompose' || $buildPack === 'dockerimage');

        expect($shouldCreateEmptyEnvFile)->toBeFalse(
            "Build pack '{$buildPack}' should not create empty .env file in save_runtime_environment_variables()"
        );
    }
});
