<?php

use App\Livewire\Storage\Create;
use Tests\TestCase;

uses(TestCase::class);

it('gates the S3 storage edit form behind the update permission', function () {
    $editView = file_get_contents(resource_path('views/livewire/storage/form.blade.php'));

    expect($editView)->toContain('@can(\'update\', $storage)');
});

it('normalizes endpoints again on the backend', function (string $endpoint, string $expected) {
    $method = new ReflectionMethod(Create::class, 'normalizeEndpoint');

    expect($method->invoke(new Create, $endpoint))->toBe($expected);
})->with([
    'missing scheme' => ['s3.example.com', 'https://s3.example.com'],
    'existing HTTP scheme' => ['http://192.168.1.50:9000', 'http://192.168.1.50:9000'],
    'malformed HTTP scheme' => ['http:/192.168.1.50:9000', 'http:/192.168.1.50:9000'],
    'hostname with port' => ['minio.internal:9000', 'https://minio.internal:9000'],
    'unsupported scheme' => ['ftp://s3.example.com', 'ftp://s3.example.com'],
    'DigitalOcean bucket endpoint' => ['https://bucket.nyc3.digitaloceanspaces.com', 'https://nyc3.digitaloceanspaces.com'],
]);
