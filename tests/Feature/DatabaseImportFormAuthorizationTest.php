<?php

it('declares explicit authorization on database import form controls', function () {
    $view = file_get_contents(resource_path('views/livewire/project/database/import-form.blade.php'));

    preg_match_all(
        '/<x-forms\.(button|input|select|checkbox|textarea)\b[^>]*>/s',
        $view,
        $matches,
        PREG_OFFSET_CAPTURE
    );

    $missingAuthorization = collect($matches[0])
        ->filter(fn (array $match): bool => ! str_contains($match[0], 'canGate=') || ! str_contains($match[0], 'canResource='))
        ->map(fn (array $match): string => 'Line '.(substr_count(substr($view, 0, $match[1]), PHP_EOL) + 1).': '.trim(preg_replace('/\s+/', ' ', $match[0])))
        ->values()
        ->all();

    expect($missingAuthorization)->toBeEmpty();
});
