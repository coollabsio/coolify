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

it('authorizes every volume backup form control', function (string $path, array $controlPatterns) {
    $source = file_get_contents(base_path($path));

    foreach ($controlPatterns as $controlPattern) {
        expect($source)->toMatch($controlPattern);
    }
})->with([
    'retention controls' => [
        'resources/views/livewire/project/shared/storages/volume-backups/retention.blade.php',
        [
            '/<x-forms\.button(?=[^>]*type="submit")(?=[^>]*canGate="update")(?=[^>]*:canResource="\$backup")[^>]*>Save<\/x-forms\.button>/s',
            '/<x-forms\.input(?=[^>]*id="retentionAmountLocally")(?=[^>]*canGate="update")(?=[^>]*:canResource="\$backup")[^>]*\/\>/s',
            '/<x-forms\.input(?=[^>]*id="retentionDaysLocally")(?=[^>]*canGate="update")(?=[^>]*:canResource="\$backup")[^>]*\/\>/s',
            '/<x-forms\.input(?=[^>]*id="retentionMaxStorageLocally")(?=[^>]*canGate="update")(?=[^>]*:canResource="\$backup")[^>]*\/\>/s',
            '/<x-forms\.input(?=[^>]*id="retentionAmountS3")(?=[^>]*canGate="update")(?=[^>]*:canResource="\$backup")[^>]*\/\>/s',
            '/<x-forms\.input(?=[^>]*id="retentionDaysS3")(?=[^>]*canGate="update")(?=[^>]*:canResource="\$backup")[^>]*\/\>/s',
            '/<x-forms\.input(?=[^>]*id="retentionMaxStorageS3")(?=[^>]*canGate="update")(?=[^>]*:canResource="\$backup")[^>]*\/\>/s',
        ],
    ],
    'S3 controls' => [
        'resources/views/livewire/project/shared/storages/volume-backups/s3.blade.php',
        [
            '/<x-forms\.button(?=[^>]*type="submit")(?=[^>]*canGate="update")(?=[^>]*:canResource="\$backup")[^>]*>Save<\/x-forms\.button>/s',
            '/<x-forms\.button(?=[^>]*wire:click="toggleS3")(?=[^>]*canGate="update")(?=[^>]*:canResource="\$backup")[^>]*>Enable S3<\/x-forms\.button>/s',
            '/<x-forms\.button(?=[^>]*wire:click="toggleS3")(?=[^>]*canGate="update")(?=[^>]*:canResource="\$backup")[^>]*>Disable S3<\/x-forms\.button>/s',
            '/<x-forms\.select(?=[^>]*id="s3StorageId")(?=[^>]*canGate="update")(?=[^>]*:canResource="\$backup")[^>]*>/s',
            '/<x-forms\.checkbox(?=[^>]*id="disableLocalBackup")(?=[^>]*instantSave)(?=[^>]*canGate="update")(?=[^>]*:canResource="\$backup")[^>]*\/\>/s',
            '/<x-forms\.checkbox(?=[^>]*id="disableLocalBackup")(?=[^>]*disabled)(?=[^>]*canGate="update")(?=[^>]*:canResource="\$backup")[^>]*\/\>/s',
        ],
    ],
    'service domains controls' => [
        'resources/views/livewire/project/service/domains.blade.php',
        [
            '/<x-forms\.select(?=[^>]*canGate="update")(?=[^>]*:canResource="\$service")(?=[^>]*id="newServiceApplicationId")[^>]*>/s',
            '/<x-forms\.input(?=[^>]*canGate="update")(?=[^>]*:canResource="\$service")(?=[^>]*id="newDomain")[^>]*>/s',
            '/<x-forms\.button(?=[^>]*canGate="update")(?=[^>]*:canResource="\$service")(?=[^>]*wire:click="generateDomain")[^>]*>/s',
            '/<x-forms\.button(?=[^>]*canGate="update")(?=[^>]*:canResource="\$service")(?=[^>]*type="submit")[^>]*>/s',
            '/wire:click="checkAllDns"/s',
        ],
    ],
]);
