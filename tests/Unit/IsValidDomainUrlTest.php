<?php

it('accepts valid domain URLs', function (string $url) {
    expect(isValidDomainUrl($url))->toBeTrue();
})->with([
    'https host' => 'https://myapp.example.com',
    'http host' => 'http://myapp.example.com',
    'underscore in host' => 'https://myapp_service.example.com',
    'multiple underscores in host' => 'https://my_app_service.example.com',
    'underscore in subdomain' => 'https://my_app.staging.example.com',
    'host with port' => 'https://myapp_service.example.com:8080',
    'underscore host with path' => 'https://myapp_service.example.com/path_to/resource',
    'hyphen host' => 'https://my-app.example.com',
]);

it('rejects invalid domain URLs', function (string $url) {
    expect(isValidDomainUrl($url))->toBeFalse();
})->with([
    'plain text' => 'not a url',
    'empty string' => '',
    'host only no scheme' => 'myapp_service.example.com',
    'scheme only' => 'https://',
    'space in host' => 'https://my app.example.com',
]);
