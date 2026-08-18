<?php

it('uses the compact height for all standard buttons', function () {
    $utilities = file_get_contents(resource_path('css/utilities.css'));
    $applicationStyles = file_get_contents(resource_path('css/app.css'));

    expect($utilities)->toContain('px-2.5 h-8 min-h-8')
        ->and($applicationStyles)
        ->not->toContain('.application-settings-workspace .button')
        ->not->toContain('.application-settings-form .button');
});
