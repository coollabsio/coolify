<?php

use App\Models\GitlabApp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;

beforeEach(function () {
    Model::encryptUsing(new Encrypter(str_repeat('a', 32), 'AES-256-CBC'));
});

afterEach(function () {
    Model::encryptUsing(null);
});

it('returns api base url with /api/v4 appended when missing', function () {
    $app = new GitlabApp(['api_url' => 'https://gitlab.example.com']);
    expect($app->apiUrlBase())->toBe('https://gitlab.example.com/api/v4');
});

it('returns api base url unchanged when /api/v4 already present', function () {
    $app = new GitlabApp(['api_url' => 'https://gitlab.example.com/api/v4']);
    expect($app->apiUrlBase())->toBe('https://gitlab.example.com/api/v4');
});

it('strips trailing slash from api url base', function () {
    $app = new GitlabApp(['api_url' => 'https://gitlab.example.com/api/v4/']);
    expect($app->apiUrlBase())->toBe('https://gitlab.example.com/api/v4');
});

it('reports connected when tokens are present', function () {
    $app = new GitlabApp([
        'access_token' => str_repeat('t', 20), // ggignore
        'refresh_token' => str_repeat('r', 20), // ggignore
    ]);
    expect($app->isConnected())->toBeTrue();
});

it('reports not connected when tokens are missing', function () {
    $app = new GitlabApp([
        'access_token' => null,
        'refresh_token' => null,
    ]);
    expect($app->isConnected())->toBeFalse();
});

it('reports not connected when only access token is present', function () {
    $app = new GitlabApp([
        'access_token' => str_repeat('t', 20), // ggignore
        'refresh_token' => null,
    ]);
    expect($app->isConnected())->toBeFalse();
});
