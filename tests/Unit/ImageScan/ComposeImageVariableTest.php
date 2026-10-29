<?php

use App\Services\ImageScan\ComposeImageVariable;

it('reads the variable of an image in the forms Coolify can change', function (string $image, string $key, bool $tagOnly, ?string $default, ?string $value, ?string $resolved) {
    $variable = ComposeImageVariable::parse($image);

    expect($variable)->not->toBeNull()
        ->and($variable->key)->toBe($key)
        ->and($variable->tagOnly)->toBe($tagOnly)
        ->and($variable->default)->toBe($default)
        ->and($variable->resolve($value))->toBe($resolved);
})->with([
    'whole image' => ['${N8N_IMAGE}', 'N8N_IMAGE', false, null, 'n8nio/n8n:1.0', 'n8nio/n8n:1.0'],
    'whole image with a default' => ['${N8N_IMAGE:-n8nio/n8n:1.0}', 'N8N_IMAGE', false, 'n8nio/n8n:1.0', null, 'n8nio/n8n:1.0'],
    'whole image without braces' => ['$N8N_IMAGE', 'N8N_IMAGE', false, null, 'n8nio/n8n:1.1', 'n8nio/n8n:1.1'],
    'tag' => ['nginx:${NGINX_VERSION}', 'NGINX_VERSION', true, null, '1.27', 'nginx:1.27'],
    'tag with a default' => ['nginx:${NGINX_VERSION:-1.27}', 'NGINX_VERSION', true, '1.27', '', 'nginx:1.27'],
    'tag of an image on a registry with a port' => ['registry.local:5000/team/app:${TAG-2.0}', 'TAG', true, '2.0', null, 'registry.local:5000/team/app:2.0'],
    'no value and no default' => ['nginx:${NGINX_VERSION}', 'NGINX_VERSION', true, null, null, null],
]);

it('refuses the forms Coolify cannot change', function (string $image) {
    expect(ComposeImageVariable::parse($image))->toBeNull();
})->with([
    'two variables' => ['${REPO}:${TAG}'],
    'variable in the name' => ['ghcr.io/${ORG}/app:1.0'],
    'variable and a suffix' => ['nginx:${VERSION}-alpine'],
    'no variable' => ['nginx:1.27'],
]);

it('gives the value for a new tag and keeps how the image name was written', function () {
    expect(ComposeImageVariable::parse('${IMAGE}')->valueFor('docker.io/library/nginx:1.27', '1.29'))->toBe('docker.io/library/nginx:1.29')
        ->and(ComposeImageVariable::parse('${IMAGE}')->valueFor('registry.local:5000/app', '2.0'))->toBe('registry.local:5000/app:2.0')
        ->and(ComposeImageVariable::parse('nginx:${TAG}')->valueFor('nginx:1.27', '1.29'))->toBe('1.29');
});
