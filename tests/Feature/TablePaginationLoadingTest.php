<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;

it('disables page size controls when the gate denies access', function () {
    Gate::define('view-audit-log-test', fn (): bool => false);

    $html = Blade::render(<<<'BLADE'
        <x-page-size-select model="perPage" livewire
            canGate="view-audit-log-test" :canResource="new stdClass" />
    BLADE);

    expect($html)->toMatch('/<button[^>]*aria-label="Items per page"[^>]*\sdisabled(?:[=\s>])/')
        ->toMatch('/<input[^>]*aria-label="Custom items per page"[^>]*\sdisabled(?:[=\s>])/');
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
