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

it('declares deploy authorization on the application stop confirmation', function () {
    $source = file_get_contents(resource_path('views/livewire/project/application/heading.blade.php'));

    expect($source)->toMatch(
        '/<x-modal-confirmation\s+canGate="deploy" :canResource="\$application"/'
    );
});

it('declares deploy authorization on the service container removal confirmation', function () {
    $source = file_get_contents(resource_path('views/livewire/project/service/heading.blade.php'));

    expect($source)->toMatch(
        '/<x-modal-confirmation(?=[^>]*title="Confirm Container Removal\?")(?=[^>]*canGate="deploy")(?=[^>]*:canResource="\$service")[^>]*>/'
    );
});

it('declares update authorization on service backup mutation controls', function () {
    $importBackupView = file_get_contents(resource_path('views/livewire/project/service/import-backup.blade.php'));
    $volumeBackupView = file_get_contents(resource_path('views/livewire/project/service/volume-backup/index.blade.php'));

    expect($importBackupView)->toMatch(
        '/<x-forms\.listbox(?=[^>]*id="selectedDatabaseUuid")(?=[^>]*canGate="update")(?=[^>]*:canResource="\$service")[^>]*>/'
    );

    expect($volumeBackupView)
        ->toMatch('/<x-modal-input(?=[^>]*:title="\'Edit backup schedule\'")(?=[^>]*canGate="update")(?=[^>]*:canResource="\$service")[^>]*>/')
        ->toMatch('/<x-forms\.button(?=[^>]*wire:click\.stop="backupNow\(\'database\',[^"]+")(?=[^>]*canGate="update")(?=[^>]*:canResource="\$service")[^>]*>/')
        ->toMatch('/<x-forms\.button(?=[^>]*wire:click\.stop="backupNow\(\'storage\',[^"]+")(?=[^>]*canGate="update")(?=[^>]*:canResource="\$service")[^>]*>/');
});

it('declares update authorization on application port controls', function () {
    $domainsView = file_get_contents(resource_path('views/livewire/project/application/domains.blade.php'));
    $previewDomainsView = file_get_contents(resource_path('views/livewire/project/application/preview-domains.blade.php'));
    $generalView = file_get_contents(resource_path('views/livewire/project/application/general.blade.php'));

    expect($domainsView)
        ->toMatch('/<x-forms\.button(?=[^>]*canGate="update")(?=[^>]*:canResource="\$application")[^>]*>\s*Cancel/s')
        ->toMatch('/<x-forms\.button(?=[^>]*wire:click="confirmUseUnknownPort")(?=[^>]*canGate="update")(?=[^>]*:canResource="\$application")[^>]*>/s');

    expect($previewDomainsView)
        ->toMatch('/<x-forms\.button[^\n]*canGate="update" :canResource="\$preview->application"[\s\S]{0,150}?Cancel/')
        ->toMatch('/<x-forms\.button[^\n]*wire:click="confirmUseUnknownPort" canGate="update"\s+:canResource="\$preview->application"/');

    $portsExposesControls = str($generalView)
        ->after("@if (\$isStatic || \$buildPack === 'static')")
        ->before('<p class="mt-1.5 text-xs');

    expect($portsExposesControls->substrCount('id="portsExposes"'))->toBe(3)
        ->and($portsExposesControls->substrCount('canGate="update" :canResource="$application"'))->toBe(3);
});

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
