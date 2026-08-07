<?php

it('renders the official Hostinger mark', function () {
    $contents = file_get_contents(__DIR__.'/../../resources/views/components/hostinger-icon.blade.php');

    expect($contents)
        ->toContain('viewBox="0 0 26 30"')
        ->toContain('M0.000249566 14.046V0.000497794')
        ->toContain('Hostinger');
});
