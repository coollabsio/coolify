<?php

use Illuminate\Support\Facades\Blade;

it('renders the shared page size selector', function () {
    $html = Blade::render('<x-page-size-select model="perPage" livewire storage-key="tests.page-size" />');

    expect($html)
        ->toContain('aria-label="Items per page"')
        ->toContain('aria-haspopup="listbox"')
        ->toContain('role="listbox"')
        ->toContain("\$wire.set('perPage'")
        ->toContain('h-7!')
        ->toContain('w-12!')
        ->toContain('selectedPageSize')
        ->toContain('border-0')
        ->toContain('text-[11px]!')
        ->toContain('mb-0!')
        ->not->toContain('<select')
        ->toContain("localStorage.getItem('tests.page-size')")
        ->toContain("localStorage.setItem('tests.page-size'")
        ->toContain('applyPageSize(10)')
        ->toContain('applyPageSize(25)')
        ->toContain('applyPageSize(50)')
        ->toContain('applyPageSize(100)')
        ->not->toContain('>Rows</span>');
    expect($html)
        ->toContain('Custom…')
        ->toContain('min="1"')
        ->toContain('max="100"');
});

it('positions table dropdown panels outside overflowing containers', function () {
    $html = Blade::render(<<<'BLADE'
        <x-table.dropdown>
            <x-slot:trigger><button type="button">Open</button></x-slot:trigger>
            <button type="button">Option</button>
        </x-table.dropdown>
    BLADE);

    expect($html)
        ->toContain('position: fixed')
        ->toContain('getBoundingClientRect()')
        ->toContain('x-on:scroll.window')
        ->not->toContain('top-auto!');
});

it('renders compact client-side pagination', function () {
    $html = Blade::render('<x-client-pagination summary="1-10 of 20" page-size-model="pageSize" storage-key="tests.page-size" />');

    expect($html)
        ->toContain('1-10 of 20')
        ->toContain('pageSize = pageSizeValue')
        ->toContain("localStorage.getItem('tests.page-size')")
        ->toContain('aria-label="Previous page"')
        ->toContain('aria-label="Next page"')
        ->not->toContain('aria-label="First page"')
        ->not->toContain('aria-label="Last page"');
});

it('renders loading indicators for livewire page navigation', function () {
    $html = Blade::render(
        '<x-table-pagination
            :from="1"
            :to="10"
            :total="13"
            :current-page="1"
            :last-page="2"
            wire-target="goToPage,previousPage,nextPage"
            first-action="goToPage(1)"
            previous-action="previousPage"
            next-action="nextPage"
            last-action="goToPage(2)"
        />'
    );

    expect($html)
        ->toContain('1–10')
        ->toContain('13')
        ->toContain('wire:target="goToPage,previousPage,nextPage"')
        ->toContain('wire:loading')
        ->toContain('wire:loading.attr="disabled"')
        ->toContain('wire:loading.inline-flex')
        ->toContain('animate-spin')
        ->toContain('Loading page…')
        ->toContain('wire:click="previousPage"')
        ->toContain('wire:click="nextPage"')
        ->toContain('aria-label="Next page"')
        ->not->toContain('aria-label="First page"')
        ->not->toContain('aria-label="Last page"');

    // A single spinner appears next to the range while Livewire loads the next page.
    expect(substr_count($html, 'animate-spin'))->toBe(1)
        ->and(substr_count($html, 'Loading page…'))->toBe(1);
});

it('omits livewire loading markup when no wire target is provided', function () {
    $html = Blade::render(
        '<x-table-pagination
            :from="1"
            :to="5"
            :total="5"
            :current-page="1"
            :last-page="1"
        />'
    );

    expect($html)
        ->toContain('1–5')
        ->not->toContain('wire:loading')
        ->not->toContain('wire:target')
        ->not->toContain('Loading page…');
});

it('uses the shared pagination loading component on deployment history', function () {
    $view = file_get_contents(resource_path('views/livewire/project/application/deployment/index.blade.php'));

    expect($view)
        ->toContain('<x-table-pagination')
        ->toContain('<x-page-size-select')
        ->toContain('wire-target="goToPage,previousPage,nextPage"')
        ->toContain('wire:loading.class="opacity-50 pointer-events-none"')
        ->toContain('wire:target="goToPage,previousPage,nextPage,toggleDeploymentFilter,clearFilter,setPullRequestFilter"');
});

it('uses the shared pagination loading component on environment variables', function () {
    $view = file_get_contents(resource_path('views/livewire/project/shared/environment-variable/all.blade.php'));

    expect($view)
        ->toContain('<x-table-pagination')
        ->toContain('<x-page-size-select')
        ->toContain('wire-target="setEnvironmentVariablePage,previousEnvironmentVariablePage,nextEnvironmentVariablePage"')
        ->toContain('wire:loading.class="pointer-events-none opacity-40 blur-[2px]"');
});

it('uses the shared pagination loading component on team admin view', function () {
    $view = file_get_contents(resource_path('views/livewire/team/admin-view.blade.php'));

    expect($view)
        ->toContain('<x-table-pagination')
        ->toContain('<x-page-size-select')
        ->toContain('wire-target="setPage,previousPage,nextPage"');
});

it('offers page size selection on client-side paginated tables', function (string $view) {
    $contents = file_get_contents(resource_path("views/{$view}"));

    expect($contents)
        ->toContain('storage-key="coolify.page-size.')
        ->toMatch('/<x-(page-size-select|client-pagination)/');
})->with([
    'team members' => 'livewire/team/member/index.blade.php',
    'api tokens' => 'livewire/security/api-tokens.blade.php',
    'scheduled executions' => 'livewire/settings/scheduled-jobs.blade.php',
    'volume backup executions' => 'livewire/project/shared/storages/volume-backups/executions.blade.php',
    'environment resources' => 'livewire/project/resource/index.blade.php',
    'projects' => 'livewire/project/index.blade.php',
    'project environments' => 'livewire/project/show.blade.php',
    'tagged resources' => 'livewire/tags/show.blade.php',
]);

it('uses compact client pagination on collection views', function (string $view) {
    $contents = file_get_contents(resource_path("views/{$view}"));

    expect($contents)->toContain('<x-client-pagination');
})->with([
    'team members' => 'livewire/team/member/index.blade.php',
    'api tokens' => 'livewire/security/api-tokens.blade.php',
    'scheduled jobs' => 'livewire/settings/scheduled-jobs.blade.php',
    'environment resources' => 'livewire/project/resource/index.blade.php',
    'projects' => 'livewire/project/index.blade.php',
    'project environments' => 'livewire/project/show.blade.php',
    'tags' => 'livewire/tags/show.blade.php',
]);
