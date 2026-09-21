<?php

test('standard buttons capitalize each word in their labels', function () {
    $utilities = file_get_contents(resource_path('css/utilities.css'));

    preg_match('/@utility button \{(?<styles>[\s\S]*?)\n\}/', $utilities, $matches);

    expect($matches['styles'] ?? '')
        ->toContain('capitalize')
        ->not->toContain('normal-case');
});
