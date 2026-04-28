<?php

// Tests for orphaned ServiceApplication/ServiceDatabase cleanup in serviceParser (#9591).
it('removes orphaned service applications whose names are no longer in the compose', function () {
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    expect($parsersFile)
        ->toContain('$currentServiceNames = collect($services)->keys()->all();')
        ->toContain('$resource->applications()')
        ->toContain("->whereNotIn('name', \$currentServiceNames)")
        ->toContain('->each(fn ($app) => $app->delete())');
});

it('removes orphaned service databases whose names are no longer in the compose', function () {
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    expect($parsersFile)
        ->toContain('$resource->databases()')
        ->toContain("->whereNotIn('name', \$currentServiceNames)")
        ->toContain('->each(fn ($db) => $db->delete())');
});

it('guards against empty service names to prevent accidental mass deletion', function () {
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    expect($parsersFile)
        ->toContain('if (! empty($currentServiceNames))');
});
