<?php

test('apexcharts delegates tooltip chrome to the custom content', function () {
    $utilities = file_get_contents(resource_path('css/utilities.css'));

    preg_match('/@utility apexcharts-tooltip \{[^}]*\}/s', $utilities, $tooltip);
    preg_match('/@utility apexcharts-tooltip-custom \{[^}]*\}/s', $utilities, $customTooltip);

    expect($tooltip[0] ?? '')
        ->toContain('overflow-visible!')
        ->toContain('rounded-none!')
        ->toContain('border-0!')
        ->toContain('bg-transparent!')
        ->toContain('shadow-none!')
        ->not->toContain('dark:border-coolgray-300!')
        ->not->toContain('dark:bg-coolgray-200!')
        ->and($customTooltip[0] ?? '')
        ->toContain('border-neutral-200')
        ->toContain('dark:border-coolgray-300')
        ->toContain('rounded-lg')
        ->toContain('shadow-lg');
});

test('metrics charts render custom tooltip content', function (string $view) {
    expect(file_get_contents(resource_path($view)))
        ->toContain('apexcharts-tooltip-custom');
})->with([
    'dashboard server metrics' => 'views/livewire/dashboard/server-metrics-chart.blade.php',
    'server metrics' => 'views/livewire/server/charts.blade.php',
    'resource metrics' => 'views/livewire/project/shared/metrics.blade.php',
]);
