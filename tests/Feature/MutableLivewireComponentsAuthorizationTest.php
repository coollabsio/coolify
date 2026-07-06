<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps mutable Livewire components behind authorization checks', function (string $path, array $requiredNeedles) {
    $source = file_get_contents(base_path($path));

    foreach ($requiredNeedles as $needle) {
        expect($source)->toContain($needle);
    }
})->with([
    'storage resources' => [
        'app/Livewire/Storage/Resources.php',
        ['AuthorizesRequests', "authorize('update'", "authorize('view'"],
    ],
    'postgres init script editor' => [
        'app/Livewire/Project/Database/InitScript.php',
        ['AuthorizesRequests', "authorize('update'"],
    ],
    'execute container command' => [
        'app/Livewire/Project/Shared/ExecuteContainerCommand.php',
        ['AuthorizesRequests', "authorize('view'", "authorize('canAccessTerminal'"],
    ],
    'terminal' => [
        'app/Livewire/Project/Shared/Terminal.php',
        ['AuthorizesRequests', "authorize('view'", "authorize('canAccessTerminal'"],
    ],
]);
