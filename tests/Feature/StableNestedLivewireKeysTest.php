<?php

it('does not key nested Livewire components by mutable list positions', function () {
    $postgresView = file_get_contents(resource_path('views/livewire/project/database/postgresql/general.blade.php'));
    $proxyView = file_get_contents(resource_path('views/livewire/server/proxy/dynamic-configurations.blade.php'));

    expect($postgresView)
        ->toContain(':wire:key="\'init-script-\'.md5($script[\'filename\'])"')
        ->not->toContain(':wire:key="$script[\'index\']"');

    expect($proxyView)
        ->toContain('wire:key="proxy-navbar-{{ $fileName }}"')
        ->not->toContain('wire:key="{{ $fileName }}-{{ $loop->index }}"');
});

it('saves reindexed PostgreSQL scripts by their original stable identity', function () {
    $editor = file_get_contents(app_path('Livewire/Project/Database/InitScript.php'));
    $parent = file_get_contents(app_path('Livewire/Project/Database/Postgresql/General.php'));

    expect($editor)
        ->toContain('public string $originalFilename;')
        ->toContain("dispatch('save_init_script', \$this->script, \$this->originalFilename)");

    expect($parent)
        ->toContain('public function save_init_script($script, string $originalFilename)')
        ->toContain("firstWhere('filename', \$originalFilename)");
});

it('keeps editable and refreshed list row keys independent of their positions', function () {
    $applicationDomains = file_get_contents(resource_path('views/livewire/project/application/partials/domain-row.blade.php'));
    $serviceDomains = file_get_contents(resource_path('views/livewire/project/service/partials/domain-table.blade.php'));
    $scheduledJobs = file_get_contents(resource_path('views/livewire/settings/scheduled-jobs.blade.php'));

    expect($applicationDomains)
        ->not->toContain('wire:key="domain-row-{{ $index }}-')
        ->toContain('wire:key="domain-row-{{ md5(');

    expect($serviceDomains)
        ->not->toContain('-{{ $index }}-')
        ->toContain('wire:key="svc-domain-{{ $row[\'service_application_id\'] ?? \'x\' }}-{{ md5(');

    expect($scheduledJobs)
        ->not->toContain('wire:key="run-{{ $loop->index }}"')
        ->not->toContain('wire:key="skip-{{ $loop->index }}"')
        ->toContain('wire:key="run-{{ md5(serialize($run)) }}"')
        ->toContain('wire:key="skip-{{ md5(serialize($skip)) }}"');
});

it('keys nested Livewire status components rendered inside navigation loops', function () {
    $sidebar = file_get_contents(resource_path('views/components/application/configuration-sidebar.blade.php'));

    expect($sidebar)
        ->toContain(':key="\'application-server-status-\'.$application->uuid"');
});

it('keys rolling log lines by content occurrence instead of list position', function () {
    $logs = file_get_contents(resource_path('views/livewire/project/shared/get-logs.blade.php'));

    expect($logs)
        ->toContain('$lineOccurrences = [];')
        ->toContain('$lineFingerprint = md5($line);')
        ->toContain('wire:key="log-{{ $lineFingerprint }}-{{ $lineOccurrence }}"')
        ->not->toContain("'line-' . \$index");
});
