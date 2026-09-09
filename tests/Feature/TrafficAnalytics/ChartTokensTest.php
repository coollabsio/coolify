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

it('keeps request values in a compact y-axis outside the plot', function () {
    $partial = file_get_contents(base_path('resources/views/livewire/traffic/_requests-chart.blade.php'));

    expect($partial)
        ->toContain('minWidth: 28')
        ->toContain('maxWidth: 28')
        ->toContain('padding: { left: 6, right: 12, top: 12, bottom: 0 }')
        ->not->toContain('floating: true')
        ->not->toContain('offsetX: 32');
});

it('renders request charts full bleed inside their analytics sections', function () {
    foreach ([
        base_path('resources/views/livewire/analytics.blade.php'),
        base_path('resources/views/livewire/project/application/analytics.blade.php'),
    ] as $viewPath) {
        $view = file_get_contents($viewPath);

        expect($view)->toContain('id="analytics-requests-section" title="Requests" flush');
    }
});

it('blends KPI rows into their analytics section surface', function () {
    foreach ([
        base_path('resources/views/livewire/analytics.blade.php'),
        base_path('resources/views/livewire/project/application/analytics.blade.php'),
    ] as $viewPath) {
        $view = file_get_contents($viewPath);

        expect($view)
            ->toContain('bg-[var(--coollabs-base)]')
            ->not->toContain('bg-white px-4 py-3 dark:bg-base');
    }
});

it('keeps the analytics KPI skeleton the same height and surface as loaded tiles', function () {
    $view = file_get_contents(base_path('resources/views/components/skeleton/tiles.blade.php'));

    expect($view)
        ->toContain('bg-[var(--coollabs-base)]')
        ->toContain("'Requests', 'Unique visitors', 'Bandwidth', 'Error rate', 'p95 latency'")
        ->toContain('tracking-wide text-neutral-500 uppercase dark:text-fg-dim')
        ->not->toContain('h-3 w-16')
        ->toContain('h-9 w-full rounded')
        ->not->toContain('dark:bg-base');
});

it('seeds the requests chart with the server-rendered series', function () {
    $partial = file_get_contents(base_path('resources/views/livewire/traffic/_requests-chart.blade.php'));

    expect($partial)
        ->toContain("'initialCategories' => array_column(\$series, 'bucket')")
        ->toContain("'initialRequests' => \$this->requestsSpark()")
        ->toContain('series: [{ name: \'Requests\', data: initialPoints }]');
});

it('waits for lazy Livewire chart elements before initializing ApexCharts', function () {
    foreach ([
        base_path('resources/views/livewire/traffic/_requests-chart.blade.php'),
        base_path('resources/views/livewire/traffic/_device-chart.blade.php'),
    ] as $partialPath) {
        $partial = file_get_contents($partialPath);

        expect($partial)
            ->toContain('requestAnimationFrame(() => {')
            ->toContain('if (!el) { return; }');
    }
});

it('treats an all-zero device series as no data', function () {
    $partial = file_get_contents(base_path('resources/views/livewire/traffic/_device-chart.blade.php'));

    expect($partial)
        ->toContain("\$hasDeviceData = array_sum(array_map('intval', \$series)) > 0")
        ->toContain('@if (! $hasDeviceData)');
});

it('renders the device chart tooltip with the shared opaque background', function () {
    $partial = file_get_contents(base_path('resources/views/livewire/traffic/_device-chart.blade.php'));

    expect($partial)
        ->toContain('apexcharts-tooltip-custom')
        ->toContain('apexcharts-tooltip-custom-value');
});

it('keeps KPI sparklines axisless after live updates', function () {
    $partial = file_get_contents(base_path('resources/views/livewire/traffic/_sparkline.blade.php'));

    expect($partial)
        ->toContain('yaxis: { labels: { show: false }')
        ->toContain("xaxis: { type: 'datetime', labels: { show: false }, axisBorder: { show: false }, axisTicks: { show: false } }")
        ->toContain('grid: { padding: { left: 4, right: 4, top: 0, bottom: 0 } }');
});

it('shows values with local and UTC timestamps in analytics chart tooltips', function () {
    $sparkline = file_get_contents(base_path('resources/views/livewire/traffic/_sparkline.blade.php'));
    $requestsChart = file_get_contents(base_path('resources/views/livewire/traffic/_requests-chart.blade.php'));

    expect($sparkline)
        ->toContain('sparkCategories')
        ->toContain('apexcharts-tooltip-custom-value')
        ->toContain('formatLocalTimestamp(timestamp)')
        ->toContain('formatUtcTimestamp(timestamp)')
        ->toContain('Your time:')
        ->toContain('UTC:')
        ->toContain("timeZoneName: 'short'")
        ->toContain("timeZone: 'UTC'");

    expect($requestsChart)
        ->toContain('apexcharts-tooltip-custom-value')
        ->toContain('formatLocalTimestamp(timestamp)')
        ->toContain('formatUtcTimestamp(timestamp)')
        ->toContain('Your time:')
        ->toContain('UTC:')
        ->toContain("timeZoneName: 'short'")
        ->toContain("timeZone: 'UTC'");

    foreach ([
        app_path('Livewire/Analytics.php'),
        app_path('Livewire/Project/Application/Analytics.php'),
        app_path('Livewire/Dashboard/TrafficAnalytics.php'),
    ] as $component) {
        expect(file_get_contents($component))->toContain("'sparkCategories' => array_column(\$this->series, 'bucket')");
    }
});

it('registers sparkline refresh listeners without Blade control-flow inside Alpine data', function () {
    $partial = file_get_contents(base_path('resources/views/livewire/traffic/_sparkline.blade.php'));

    expect($partial)
        ->not->toContain('@if ($event && $key)')
        ->toContain('if (event && key)')
        ->toContain('this.refreshCleanup = Livewire.on(event')
        ->toContain('destroy()');
});

it('lets KPI hover markers overflow without shrinking the chart scale', function () {
    $partial = file_get_contents(base_path('resources/views/livewire/traffic/_sparkline.blade.php'));

    expect($partial)
        ->toContain('[&_.apexcharts-svg]:overflow-visible!')
        ->not->toContain('headroom')
        ->toContain('markers: { size: 0, hover: { size: 5, sizeOffset: 2 } }');
});

it('uses the standard page title styling in the analytics view and placeholder', function () {
    foreach ([
        base_path('resources/views/livewire/analytics.blade.php'),
        base_path('resources/views/livewire/analytics-placeholder.blade.php'),
    ] as $view) {
        $contents = file_get_contents($view);

        expect($contents)
            ->toContain('<h1 class="min-w-0 text-[24px]! leading-7! font-semibold! tracking-tight!">Analytics</h1>')
            ->not->toContain('<x-reicon name="analytics"');
    }
});
