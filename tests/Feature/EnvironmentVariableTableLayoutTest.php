<?php

/**
 * Resource environment variables table: full names, Managed as a column,
 * Type owns Production/Preview (no desktop Production badge in the name cell).
 */
test('resource environment variables table has a Managed column and no name-cell Production badge on desktop', function () {
    $all = file_get_contents(resource_path('views/livewire/project/shared/environment-variable/all.blade.php'));
    $show = file_get_contents(resource_path('views/livewire/project/shared/environment-variable/show.blade.php'));
    $hardcoded = file_get_contents(resource_path('views/livewire/project/shared/environment-variable/show-hardcoded.blade.php'));
    $css = file_get_contents(resource_path('css/app.css'));

    // Header includes Managed between Name and Type.
    expect($all)
        ->toContain('$showEnvironmentType = $showPreview')
        ->toContain("toggleVariableFilter('{{ \$value }}')")
        ->toContain('toggleServiceFilter(@js($serviceName))')
        ->toContain('$this->serviceFilterOptions')
        ->toContain('toggleVariableFilter,toggleServiceFilter,clearFilters,setEnvironmentFilter')
        ->toContain('wire:loading.flex wire:target="clearFilters"')
        ->toContain('wire:click="clearFilters"')
        ->toContain('Clear filters')
        ->toContain('max-h-80 overflow-y-auto p-1')
        ->toContain('min-w-44! overflow-hidden! p-0!')
        ->toContain('relative z-20 border-t')
        ->toContain('dark:bg-[#171717]')
        ->not->toContain("'all' => 'All variables'")
        ->toContain("setTableSort('{{ \$value }}')")
        ->toContain('Loading environment variables...')
        ->toContain('opacity-40 blur-[2px]')
        ->toContain('setEnvironmentVariablePage,previousEnvironmentVariablePage,nextEnvironmentVariablePage')
        ->toContain("'buildtime' => 'Buildtime'")
        ->toContain("'runtime' => 'Runtime'")
        ->toContain("'multiline' => 'Multiline'")
        ->toContain("'literal' => 'Literal'")
        ->toContain('$activeFilterCount')
        ->toContain('$activeFilterText')
        ->toContain("'button max-w-80 min-w-0'")
        ->toContain("'flex size-4 shrink-0 items-center justify-center rounded-[5px] border'")
        ->toContain('m2.25 6.15 2.35 2.3 5.15-5')
        ->toContain('>Name</span>')
        ->toContain('>Managed</span>')
        ->toContain('>Type</span>');

    // Name cell does not repeat the environment type; Type owns Production/Preview.
    expect($show)
        ->toContain('env-managed-desktop')
        ->toContain('env-type-desktop')
        ->not->toContain('env-type-mobile')
        ->not->toContain('env-managed-mobile')
        ->toContain('env-managed-desktop data-table-cell-check')
        ->toContain('$isMagicVariable');

    // Production/Managed desktop badges must not sit bare in the name cell without mobile class.
    expect($show)->not->toMatch(
        '/env-key-label[\s\S]{0,400}<span class="table-badge shrink-0">Managed<\/span>/'
    );
    expect($show)->not->toMatch(
        '/env-key-label[\s\S]{0,500}<span class="table-badge shrink-0">\{\{\s*\$rowScopeLabel\s*\}\}<\/span>/'
    );

    expect($hardcoded)
        ->toContain('env-managed-desktop data-table-cell-check')
        ->toContain('title="Environment variable details"')
        ->toContain('<x-forms.input label="Value" :value="$value ?? \'\'" readonly />')
        ->not->toContain("{{ filled(\$value) ? \$value : '(empty)' }}")
        ->toContain('env-type-desktop')
        ->not->toContain('env-type-mobile')
        ->not->toContain('env-managed-mobile');

    // Mobile-only badge classes beat .table-badge display on desktop.
    expect($css)
        ->toContain('.env-managed-desktop')
        // Resource grid is 9 columns (includes Managed).
        ->toContain('minmax(14rem, 2.5fr) 4.8rem 6rem 4rem 4.5rem 4.8rem 4.2rem 3rem');

    expect($all)->not->toContain('<span>Comment</span>');
    expect($show)->toContain('<x-helper :helper="e($comment)" />');
});

test('shared environment variables table still omits Managed column', function () {
    $editor = file_get_contents(resource_path('views/components/shared-variables/editor.blade.php'));
    $show = file_get_contents(resource_path('views/livewire/project/shared/environment-variable/show.blade.php'));

    expect($editor)
        ->toContain('env-table-grid-shared')
        ->not->toContain('>Managed</span>');

    // Shared rows skip the Managed column cell.
    expect($show)->toContain('! $isSharedVariable');
});

test('shared environment variables empty state has spacing around the card', function () {
    $editor = file_get_contents(resource_path('views/components/shared-variables/editor.blade.php'));

    expect($editor)
        ->toMatch('/<div class="p-3">\s*<x-empty title="No shared variables"/');
});

test('managed environment variables are ordered first', function () {
    $component = file_get_contents(app_path('Livewire/Project/Shared/EnvironmentVariable/All.php'));

    expect($component)
        ->toContain("CASE WHEN key LIKE 'SERVICE_FQDN%'")
        ->toMatch("/'kind' => 'hardcoded',[\\s\\S]+?'kind' => 'managed'/");
});
