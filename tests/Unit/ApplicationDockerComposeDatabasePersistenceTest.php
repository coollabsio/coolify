<?php

it('persists detected databases for dockercompose applications using application_id ownership', function () {
    $sharedPhp = file_get_contents(base_path('bootstrap/helpers/shared.php'));

    expect($sharedPhp)
        ->toContain("where('application_id', $resource->id)")
        ->toContain("ServiceDatabase::create([");
});

it('supports migrated service subtype handling for dockercompose application parsing', function () {
    $sharedPhp = file_get_contents(base_path('bootstrap/helpers/shared.php'));

    expect($sharedPhp)
        ->toContain("->where('is_migrated', true)")
        ->toContain('$migratedApp || $migratedDb');
});
