<?php

use App\Models\S3Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    seedBrowserResourceStack();
});

it('turns path-style URLs off when the user enters the endpoint of a provider that rejects them', function () {
    $storage = S3Storage::create([
        'team_id' => 0,
        'name' => 'Backups',
        'region' => 'us-east-1',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => 'backups',
        'endpoint' => 'https://minio.example.com',
    ]);
    loginAndSkipBoarding();

    $page = visit(route('storage.show', ['storage_uuid' => $storage->uuid]));
    $pathStyleChecked = fn () => $page->script("document.querySelector('form.application-settings-form input[wire\\\\:model=\"usePathStyleEndpoint\"]').checked");
    expect($pathStyleChecked())->toBeTrue();

    $page->fill('form.application-settings-form #endpointParts-host', 's3.oss-ap-southeast-1.aliyuncs.com')
        ->click('form.application-settings-form label[for="endpointParts-host"]')
        ->wait(1);

    expect($pathStyleChecked())->toBeFalse();
    $page->screenshot(filename: 's3-storage-path-style-detected');
});
