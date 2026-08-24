<?php

// Guards the section-C tokenization: the analytics charts must read their colors
// from the shared --chart-* design tokens, not from inlined hex arrays.

it('defines the chart design tokens in the app stylesheet', function () {
    $css = file_get_contents(base_path('resources/css/app.css'));

    foreach ([
        '--chart-status-2xx',
        '--chart-status-3xx',
        '--chart-status-4xx',
        '--chart-status-5xx',
        '--chart-geo-1',
        '--chart-geo-2',
        '--chart-geo-3',
        '--chart-geo-4',
        '--chart-geo-5',
        '--chart-geo-empty',
    ] as $token) {
        expect($css)->toContain($token);
    }

    // Dark overrides must exist so the palette is a selected dark theme, not a flip.
    expect($css)->toContain('.dark {');
});

it('no longer hardcodes status color hex arrays in the analytics views', function () {
    $views = [
        base_path('resources/views/livewire/analytics.blade.php'),
        base_path('resources/views/livewire/project/application/analytics.blade.php'),
    ];

    foreach ($views as $view) {
        $contents = file_get_contents($view);

        expect($contents)->not->toContain('statusColorsLight');
        expect($contents)->not->toContain('statusColorsDark');
        // The chart lives in the shared partial; the views just pull it in.
        expect($contents)->toContain("@include('livewire.traffic._requests-chart')");
    }
});

it('reads its accent color from a design token in the shared requests-chart partial', function () {
    $partial = file_get_contents(base_path('resources/views/livewire/traffic/_requests-chart.blade.php'));

    expect($partial)->not->toContain('statusColorsLight');
    expect($partial)->not->toContain('statusColorsDark');
    expect($partial)->toContain('--chart-status-3xx');
});
