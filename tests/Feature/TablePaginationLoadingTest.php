<?php

use Illuminate\Support\Facades\Blade;

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
        ->toContain('Showing')
        ->toContain('1–10')
        ->toContain('13')
        ->toContain('wire:target="goToPage,previousPage,nextPage"')
        ->toContain('wire:loading')
        ->toContain('wire:loading.remove')
        ->toContain('wire:loading.attr="disabled"')
        ->toContain('wire:loading.inline-flex')
        ->toContain('animate-spin')
        ->toContain('Loading page…')
        ->toContain('wire:click="goToPage(1)"')
        ->toContain('wire:click="previousPage"')
        ->toContain('wire:click="nextPage"')
        ->toContain('wire:click="goToPage(2)"')
        ->toContain('aria-label="First page"')
        ->toContain('aria-label="Next page"');

    // Single spinner only (page number slot), not duplicated next to the "Showing" label.
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
        ->toContain('Showing')
        ->toContain('1–5')
        ->not->toContain('wire:loading')
        ->not->toContain('wire:target')
        ->not->toContain('Loading page…');
});

it('uses the shared pagination loading component on deployment history', function () {
    $view = file_get_contents(resource_path('views/livewire/project/application/deployment/index.blade.php'));

    expect($view)
        ->toContain('<x-table-pagination')
        ->toContain('wire-target="goToPage,previousPage,nextPage"')
        ->toContain('wire:loading.class="opacity-50 pointer-events-none"')
        ->toContain('wire:target="goToPage,previousPage,nextPage"');
});

it('uses the shared pagination loading component on environment variables', function () {
    $view = file_get_contents(resource_path('views/livewire/project/shared/environment-variable/all.blade.php'));

    expect($view)
        ->toContain('<x-table-pagination')
        ->toContain('wire-target="setEnvironmentVariablePage,previousEnvironmentVariablePage,nextEnvironmentVariablePage"')
        ->toContain('wire:loading.class="opacity-50 pointer-events-none"');
});

it('uses the shared pagination loading component on team admin view', function () {
    $view = file_get_contents(resource_path('views/livewire/team/admin-view.blade.php'));

    expect($view)
        ->toContain('<x-table-pagination')
        ->toContain('wire-target="setPage,previousPage,nextPage"');
});
