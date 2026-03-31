<?php

use App\Rules\SafeExternalUrl;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

uses(TestCase::class);

it('accepts valid public URLs', function () {
    $rule = new SafeExternalUrl;

    $validUrls = [
        'https://api.github.com',
        'https://github.example.com/api/v3',
        'https://example.com',
        'http://example.com',
    ];

    foreach ($validUrls as $url) {
        $validator = Validator::make(['url' => $url], ['url' => $rule]);
        expect($validator->passes())->toBeTrue("Expected valid: {$url}");
    }
});

it('rejects private IPv4 addresses', function (string $url) {
    $rule = new SafeExternalUrl;

    $validator = Validator::make(['url' => $url], ['url' => $rule]);
    expect($validator->fails())->toBeTrue("Expected rejection: {$url}");
})->with([
    'loopback' => 'http://127.0.0.1',
    'loopback with port' => 'http://127.0.0.1:6379',
    '10.x range' => 'http://10.0.0.1',
    '172.16.x range' => 'http://172.16.0.1',
    '192.168.x range' => 'http://192.168.1.1',
]);

it('rejects cloud metadata IP', function () {
    $rule = new SafeExternalUrl;

    $validator = Validator::make(['url' => 'http://169.254.169.254'], ['url' => $rule]);
    expect($validator->fails())->toBeTrue('Expected rejection: cloud metadata IP');
});

it('rejects localhost and internal hostnames', function (string $url) {
    $rule = new SafeExternalUrl;

    $validator = Validator::make(['url' => $url], ['url' => $rule]);
    expect($validator->fails())->toBeTrue("Expected rejection: {$url}");
})->with([
    'localhost' => 'http://localhost',
    'localhost with port' => 'http://localhost:8080',
    'zero address' => 'http://0.0.0.0',
    '.local domain' => 'http://myservice.local',
    '.internal domain' => 'http://myservice.internal',
]);

it('rejects non-URL strings', function (string $value) {
    $rule = new SafeExternalUrl;

    $validator = Validator::make(['url' => $value], ['url' => $rule]);
    expect($validator->fails())->toBeTrue("Expected rejection: {$value}");
})->with([
    'plain string' => 'not-a-url',
    'ftp scheme' => 'ftp://example.com',
    'javascript scheme' => 'javascript:alert(1)',
    'no scheme' => 'example.com',
]);

it('rejects URLs with IPv6 loopback', function () {
    $rule = new SafeExternalUrl;

    $validator = Validator::make(['url' => 'http://[::1]'], ['url' => $rule]);
    expect($validator->fails())->toBeTrue('Expected rejection: IPv6 loopback');
});
