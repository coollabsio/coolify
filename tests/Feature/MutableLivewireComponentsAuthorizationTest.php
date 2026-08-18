<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

it('auto-disables listboxes when the gate denies access', function () {
    Gate::define('update-listbox-test', fn (): bool => false);

    $html = Blade::render(<<<'BLADE'
        <x-forms.listbox id="status" :wire="false" value="enabled"
            canGate="update-listbox-test" :canResource="new stdClass"
            :options="[['value' => 'enabled', 'label' => 'Enabled']]" />
    BLADE);

    expect($html)->toMatch('/<button[^>]*id="status-trigger"[^>]*\sdisabled(?:[=\s>])/');
});

it('declares gate attributes on form controls with update permission checks', function () {
    $controlPattern = '/<x-forms\.(?:listbox|input|select|checkbox|textarea|button|toggle)\b.*?(?:\/>|<\/x-forms\.[^>]+>)/s';

    foreach (File::allFiles(resource_path('views')) as $file) {
        $path = $file->getPathname();
        $source = file_get_contents($path);
        preg_match_all($controlPattern, $source, $controls);

        foreach ($controls[0] as $control) {
            if (! preg_match('/can\(\s*[\'\"]update[\'\"]/', $control)) {
                continue;
            }

            expect($control, $path)->toContain('canGate="update"')
                ->toContain(':canResource=');
        }
    }
});

it('hides resource action menus when the user cannot manage the resource', function (string $path, string $ability, string $resource, string $prefix) {
    $source = file_get_contents(resource_path($path));

    foreach (['mobile', 'desktop'] as $viewport) {
        expect($source)->toMatch(
            "/@can\\('{$ability}', \\$".$resource."\\)[\\s\\S]*?<div id=\"{$prefix}-{$viewport}-actions\"/"
        );
    }
})->with([
    'application actions' => ['views/livewire/project/application/heading.blade.php', 'deploy', 'application', 'application'],
    'service actions' => ['views/livewire/project/service/heading.blade.php', 'deploy', 'service', 'service'],
    'database actions' => ['views/livewire/project/database/heading.blade.php', 'manage', 'database', 'database'],
    'server actions' => ['views/livewire/server/navbar.blade.php', 'manageProxy', 'server', 'server'],
]);

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
    'postgres public access control' => [
        'resources/views/livewire/project/database/postgresql/general.blade.php',
        [
            '/<x-forms\.listbox id="isPublic"[\s\S]*?:disabled="! auth\(\)->user\(\)->can\(\'update\', \$database\)" canGate="update" :canResource="\$database" :options=/',
        ],
    ],
    'redis public access control' => [
        'resources/views/livewire/project/database/redis/general.blade.php',
        ['/<x-forms\.listbox id="isPublic"[\s\S]*?canGate="update" :canResource="\$database" :options=/'],
    ],
    'mongodb public access control' => [
        'resources/views/livewire/project/database/mongodb/general.blade.php',
        ['/<x-forms\.listbox id="isPublic"[\s\S]*?canGate="update" :canResource="\$database" :options=/'],
    ],
    'clickhouse public access control' => [
        'resources/views/livewire/project/database/clickhouse/general.blade.php',
        ['/<x-forms\.listbox id="isPublic"[\s\S]*?canGate="update" :canResource="\$database" :options=/'],
    ],
    'mariadb public access control' => [
        'resources/views/livewire/project/database/mariadb/general.blade.php',
        ['/<x-forms\.listbox id="isPublic"[\s\S]*?canGate="update" :canResource="\$database" :options=/'],
    ],
    'dragonfly public access control' => [
        'resources/views/livewire/project/database/dragonfly/general.blade.php',
        ['/<x-forms\.listbox id="isPublic"[\s\S]*?canGate="update" :canResource="\$database" :options=/'],
    ],
    'mysql public access control' => [
        'resources/views/livewire/project/database/mysql/general.blade.php',
        ['/<x-forms\.listbox id="isPublic"[\s\S]*?canGate="update" :canResource="\$database" :options=/'],
    ],
    'keydb public access control' => [
        'resources/views/livewire/project/database/keydb/general.blade.php',
        ['/<x-forms\.listbox id="isPublic"[\s\S]*?canGate="update" :canResource="\$database" :options=/'],
    ],
]);
