<?php

test('native select values stay clear of the dropdown icon on mobile', function () {
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($styles)
        ->toMatch('/\.application-settings-workspace \.select,\s*\.application-settings-form \.select \{\s*padding-right: 2\.5rem;/')
        ->toMatch('/@media \(max-width: 767px\) \{\s*\.application-settings-workspace \.select,\s*\.application-settings-form \.select \{\s*font-size: 0\.75rem !important;/');
});
