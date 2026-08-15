<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

it('renders configurable host copy for S3 endpoints', function () {
    View::share('errors', new ViewErrorBag);

    $html = Blade::render(<<<'BLADE'
        <x-forms.domain-input id="endpoint" :wire="false" value="http://192.168.1.50:9000/s3"
            host-label="Host" host-placeholder="minio.internal or 192.168.1.50" />
    BLADE);

    expect($html)
        ->toContain('Host')
        ->toContain('minio.internal or 192.168.1.50')
        ->toContain("scheme: 'https'")
        ->toContain("host: ''")
        ->toContain("port: ''")
        ->toContain("path: ''");
});

it('keeps validation errors attached to the composed endpoint', function () {
    $settingsUrl = route('settings.advanced').'#endpoint-section';
    $errors = new ViewErrorBag;
    $errors->put('default', new MessageBag([
        'endpoint' => "The endpoint is invalid. Configure allowed internal targets: {$settingsUrl}",
    ]));
    View::share('errors', $errors);

    $html = Blade::render('<x-forms.domain-input id="endpoint" :wire="false" />');

    expect($html)
        ->toContain('The endpoint is invalid.')
        ->toContain('href="'.$settingsUrl.'"')
        ->toContain('Set them here.');
});
