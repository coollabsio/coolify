<?php

function renderedDataLineTextValue(string $logContent): string
{
    $escapedContent = e($logContent);
    $html = '<span data-line-text="'.$escapedContent.'">'.$escapedContent.'</span>';

    $document = new DOMDocument;
    $document->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

    return $document->documentElement->getAttribute('data-line-text');
}

describe('Log Viewer HTML Tag Preservation', function () {
    it('lets Blade escape deployment log data attributes only once', function () {
        $view = file_get_contents(__DIR__.'/../../resources/views/livewire/project/application/deployment/show.blade.php');

        expect($view)
            ->toContain('data-log-content="{{ $searchableContent }}"')
            ->toContain('data-line-text="{{ $lineContent }}"')
            ->not->toContain('data-log-content="{{ htmlspecialchars($searchableContent) }}"')
            ->not->toContain('data-line-text="{{ htmlspecialchars($lineContent) }}"');
    });

    it('preserves literal html-like log text for client-side search reset and highlighting', function () {
        $logContent = '<div>A</div>';

        expect(renderedDataLineTextValue($logContent))->toBe($logContent);
    });

    it('does not strip tags with DOMParser based html decoding', function () {
        $views = [
            __DIR__.'/../../resources/views/livewire/project/application/deployment/show.blade.php',
            __DIR__.'/../../resources/views/livewire/project/shared/get-logs.blade.php',
        ];

        foreach ($views as $view) {
            expect(file_get_contents($view))
                ->not->toContain('decodeHtml(text)')
                ->not->toContain('DOMParser().parseFromString');
        }
    });
});

describe('Log Viewer XSS Prevention', function () {
    it('keeps script-like log output as text in Blade rendered markup', function () {
        $maliciousLog = '<script>alert("XSS")</script>';
        $escapedLog = e($maliciousLog);

        expect($escapedLog)
            ->toContain('&lt;script&gt;')
            ->not->toContain('<script>');
    });

    it('keeps dangerous attributes as literal dataset text for textContent rendering', function () {
        $maliciousLog = '<img src=x onerror="alert(1)">';

        expect(renderedDataLineTextValue($maliciousLog))->toBe($maliciousLog);
    });

    it('uses text nodes for search highlighting instead of injected html', function () {
        $views = [
            __DIR__.'/../../resources/views/livewire/project/application/deployment/show.blade.php',
            __DIR__.'/../../resources/views/livewire/project/shared/get-logs.blade.php',
        ];

        foreach ($views as $view) {
            $contents = file_get_contents($view);

            expect($contents)
                ->toContain('document.createTextNode')
                ->toContain('mark.textContent')
                ->not->toContain('innerHTML')
                ->not->toContain('x-html');
        }
    });
});
