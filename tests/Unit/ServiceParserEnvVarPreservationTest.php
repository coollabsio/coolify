<?php

/**
 * Unit tests to verify that Docker Compose environment variables
 * do not overwrite user-saved values on redeploy.
 *
 * Regression test for GitHub issues #8885 and #9136.
 */
it('uses firstOrCreate for simple variable references in serviceParser to preserve user values', function () {
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // The serviceParser function should use firstOrCreate (not updateOrCreate)
    // for simple variable references like DATABASE_URL: ${DATABASE_URL}
    // This is the key === parsedValue branch
    expect($parsersFile)->toContain(
        "// Simple variable reference (e.g. DATABASE_URL: \${DATABASE_URL})\n".
        "                // Ensure the variable exists in DB for .env generation and UI display\n".
        '                $resource->environment_variables()->firstOrCreate('
    );
});

it('does not set value to null for simple variable references in serviceParser', function () {
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // The old bug: $value = null followed by updateOrCreate with 'value' => $value
    // This pattern should NOT exist for simple variable references
    expect($parsersFile)->not->toContain(
        "\$value = null;\n".
        '                $resource->environment_variables()->updateOrCreate('
    );
});

it('uses firstOrCreate for simple variable refs without default in serviceParser balanced brace path', function () {
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // In the balanced brace extraction path, simple variable references without defaults
    // should use firstOrCreate to preserve user-saved values
    // This appears twice (applicationParser and serviceParser)
    $count = substr_count(
        $parsersFile,
        "// Simple variable reference without default\n".
        "                            // Use firstOrCreate to avoid overwriting user-saved values on redeploy\n".
        '                            $envVar = $resource->environment_variables()->firstOrCreate('
    );

    expect($count)->toBe(1, 'serviceParser should use firstOrCreate for simple variable refs without default');
});

it('does not replace self-referencing variable values in the environment array', function () {
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // Fix for #9136: self-referencing variables (KEY=${KEY}) must NOT have their ${VAR}
    // reference replaced with the DB value in the compose environment section.
    // Instead, the reference should stay intact so Docker Compose resolves from .env at deploy time.
    // This prevents stale values when users update env vars without re-parsing compose.
    expect($parsersFile)->toContain('Keep the ${VAR} reference in compose');
    expect($parsersFile)->not->toContain('$environment[$key->value()] = $envVar->value;');
});

it('does not use updateOrCreate with value null for user-editable environment variables', function () {
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    // The specific bug pattern: setting $value = null then calling updateOrCreate with 'value' => $value
    // This overwrites user-saved values with null on every deploy
    expect($parsersFile)->not->toContain(
        "\$value = null;\n".
        '                $resource->environment_variables()->updateOrCreate('
    );
});
