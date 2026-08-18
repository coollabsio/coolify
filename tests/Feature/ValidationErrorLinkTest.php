<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

it('renders a trailing validation error URL as a link', function () {
    $settingsUrl = route('settings.advanced').'#endpoint-section';
    $errors = new ViewErrorBag;
    $errors->put('default', new MessageBag([
        'endpoint' => "Local or private IP addresses are not allowed. Configure allowed internal targets: {$settingsUrl}",
    ]));
    View::share('errors', $errors);

    $html = Blade::render('<x-forms.input id="endpoint" />');

    expect($html)
        ->toContain('href="'.$settingsUrl.'"')
        ->toContain('Set them here');
});
