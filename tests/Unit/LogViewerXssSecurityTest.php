<?php

describe('Log Viewer HTML Tag Preservation', function () {
    it('lets Blade escape deployment log data attributes only once', function () {
        $view = file_get_contents(__DIR__.'/../../resources/views/livewire/project/application/deployment/show.blade.php');

        expect($view)
            ->toContain('data-log-content="{{ $searchableContent }}"')
            ->toContain('data-line-text="{{ $lineContent }}"')
            ->not->toContain('data-log-content="{{ htmlspecialchars($searchableContent) }}"')
            ->not->toContain('data-line-text="{{ htmlspecialchars($lineContent) }}"');
    });
});

describe('Log Viewer XSS Prevention', function () {
    it('uses text nodes for search highlighting instead of injected html', function () {
        $deploymentView = file_get_contents(__DIR__.'/../../resources/views/livewire/project/application/deployment/show.blade.php');

        expect($deploymentView)
            ->toContain('document.createTextNode')
            ->toContain('mark.textContent')
            ->not->toContain('innerHTML')
            ->not->toContain('x-html');
    });

    it('renders runtime log text and highlight segments with x-text only', function () {
        $runtimeView = file_get_contents(__DIR__.'/../../resources/views/livewire/project/shared/get-logs.blade.php');
        $runtimeScript = file_get_contents(__DIR__.'/../../resources/js/runtime-logs.js');

        expect($runtimeView)
            ->toContain('x-text="segment.text"')
            ->toContain('x-text="formatLogDetails(row.line.text)"')
            ->not->toContain('innerHTML')
            ->not->toContain('x-html');

        expect($runtimeScript)
            ->not->toContain('innerHTML')
            ->not->toContain('insertAdjacentHTML');
    });
});
