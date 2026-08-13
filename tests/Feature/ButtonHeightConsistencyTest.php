<?php

it('uses the standard button height inside application forms and workspaces', function () {
    $utilities = file_get_contents(resource_path('css/utilities.css'));
    $applicationStyles = file_get_contents(resource_path('css/app.css'));

    expect($utilities)->toContain('px-2.5 h-9 min-h-9')
        ->and($applicationStyles)
        ->not->toContain('.application-settings-workspace .button')
        ->not->toContain('.application-settings-form .button');
});
