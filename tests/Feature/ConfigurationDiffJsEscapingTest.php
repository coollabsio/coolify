<?php

use Illuminate\Support\Facades\Blade;

it('encodes change keys as JavaScript strings in the configuration diff', function () {
    $key = "environment.it's_a_key";
    $html = Blade::render('<x-deployment.configuration-diff :diff="$diff" />', ['diff' => ['changes' => [[
        'key' => $key,
        'section_label' => 'Environment',
        'label' => str_repeat('A', 30),
        'old_display_value' => 'a',
        'new_display_value' => 'b',
        'expandable' => true,
    ]]]]);

    expect($html)->toContain('expandedRows['.e(Js::from($key)).']')
        ->not->toContain("environment.it's")
        ->not->toContain('environment.it&#039;s')
        ->not->toContain('@js(');
});
