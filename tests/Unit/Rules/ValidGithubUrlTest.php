<?php

use App\Rules\ValidGithubUrl;

it('accepts valid github.com API URL', function () {
    $rule = new ValidGithubUrl;
    $valid = true;

    $rule->validate('api_url', 'https://api.github.com', function ($message) use (&$valid) {
        $valid = false;
    });

    expect($valid)->toBeTrue();
});

it('accepts valid github.com HTML URL', function () {
    $rule = new ValidGithubUrl;
    $valid = true;

    $rule->validate('html_url', 'https://github.com', function ($message) use (&$valid) {
        $valid = false;
    });

    expect($valid)->toBeTrue();
});

it('accepts valid GitHub Enterprise URL', function () {
    $rule = new ValidGithubUrl;
    $valid = true;

    $rule->validate('api_url', 'https://github.example.com/api/v3', function ($message) use (&$valid) {
        $valid = false;
    });

    expect($valid)->toBeTrue();
});

it('accepts empty value', function () {
    $rule = new ValidGithubUrl;
    $valid = true;

    $rule->validate('api_url', '', function ($message) use (&$valid) {
        $valid = false;
    });

    expect($valid)->toBeTrue();
});

it('rejects HTTP URLs', function () {
    $rule = new ValidGithubUrl;
    $valid = true;

    $rule->validate('api_url', 'http://api.github.com', function ($message) use (&$valid) {
        $valid = false;
    });

    expect($valid)->toBeFalse();
});

it('rejects localhost URLs', function () {
    $rule = new ValidGithubUrl;
    $valid = true;

    $rule->validate('api_url', 'https://localhost', function ($message) use (&$valid) {
        $valid = false;
    });

    expect($valid)->toBeFalse();
});

it('rejects 127.0.0.1 URLs', function () {
    $rule = new ValidGithubUrl;
    $valid = true;

    $rule->validate('api_url', 'https://127.0.0.1', function ($message) use (&$valid) {
        $valid = false;
    });

    expect($valid)->toBeFalse();
});

it('rejects 0.0.0.0 URLs', function () {
    $rule = new ValidGithubUrl;
    $valid = true;

    $rule->validate('api_url', 'https://0.0.0.0', function ($message) use (&$valid) {
        $valid = false;
    });

    expect($valid)->toBeFalse();
});

it('rejects AWS metadata endpoint IP', function () {
    $rule = new ValidGithubUrl;
    $valid = true;

    $rule->validate('api_url', 'https://169.254.169.254', function ($message) use (&$valid) {
        $valid = false;
    });

    expect($valid)->toBeFalse();
});

it('rejects private IP 10.x.x.x', function () {
    $rule = new ValidGithubUrl;
    $valid = true;

    $rule->validate('api_url', 'https://10.0.0.1', function ($message) use (&$valid) {
        $valid = false;
    });

    expect($valid)->toBeFalse();
});

it('rejects private IP 192.168.x.x', function () {
    $rule = new ValidGithubUrl;
    $valid = true;

    $rule->validate('api_url', 'https://192.168.1.1', function ($message) use (&$valid) {
        $valid = false;
    });

    expect($valid)->toBeFalse();
});

it('rejects private IP 172.16.x.x', function () {
    $rule = new ValidGithubUrl;
    $valid = true;

    $rule->validate('api_url', 'https://172.16.0.1', function ($message) use (&$valid) {
        $valid = false;
    });

    expect($valid)->toBeFalse();
});

it('rejects .local domains', function () {
    $rule = new ValidGithubUrl;
    $valid = true;

    $rule->validate('api_url', 'https://myhost.local', function ($message) use (&$valid) {
        $valid = false;
    });

    expect($valid)->toBeFalse();
});

it('rejects .internal domains', function () {
    $rule = new ValidGithubUrl;
    $valid = true;

    $rule->validate('api_url', 'https://metadata.google.internal', function ($message) use (&$valid) {
        $valid = false;
    });

    expect($valid)->toBeFalse();
});

it('rejects URLs with user credentials', function () {
    $rule = new ValidGithubUrl;
    $valid = true;

    $rule->validate('api_url', 'https://user:pass@github.com', function ($message) use (&$valid) {
        $valid = false;
    });

    expect($valid)->toBeFalse();
});

it('rejects FTP scheme', function () {
    $rule = new ValidGithubUrl;
    $valid = true;

    $rule->validate('api_url', 'ftp://github.com', function ($message) use (&$valid) {
        $valid = false;
    });

    expect($valid)->toBeFalse();
});

it('rejects invalid URL format', function () {
    $rule = new ValidGithubUrl;
    $valid = true;

    $rule->validate('api_url', 'not-a-url', function ($message) use (&$valid) {
        $valid = false;
    });

    expect($valid)->toBeFalse();
});

it('accepts HTTPS URL with port', function () {
    $rule = new ValidGithubUrl;
    $valid = true;

    $rule->validate('api_url', 'https://github.example.com:8443/api/v3', function ($message) use (&$valid) {
        $valid = false;
    });

    expect($valid)->toBeTrue();
});

it('rejects IPv6 loopback', function () {
    $rule = new ValidGithubUrl;
    $valid = true;

    $rule->validate('api_url', 'https://[::1]', function ($message) use (&$valid) {
        $valid = false;
    });

    expect($valid)->toBeFalse();
});
