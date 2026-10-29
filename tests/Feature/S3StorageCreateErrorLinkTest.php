<?php

use App\Livewire\Storage\Create;
use Livewire\Livewire;

it('formats the internal target settings route as a clickable link', function () {
    $settingsUrl = route('settings.advanced').'#endpoint-section';
    $exception = new RuntimeException("Private target. Configure allowed internal targets: {$settingsUrl}");
    $method = new ReflectionMethod(Create::class, 'connectionErrorDescription');

    $description = $method->invoke(new Create, $exception);

    expect($description)
        ->toContain('href="'.$settingsUrl.'"')
        ->toContain('Set them here.')
        ->not->toContain('targets: '.$settingsUrl);
});

it('turns path-style URLs off when the endpoint belongs to a provider that rejects them', function () {
    Livewire::test(Create::class)
        ->set('endpointParts.host', 's3.oss-ap-southeast-1.aliyuncs.com')
        ->assertSet('usePathStyleEndpoint', false)
        ->set('endpointParts.host', 'minio.internal')
        ->assertSet('usePathStyleEndpoint', true);
});

it('keeps the URL style that the user selected when the endpoint changes', function () {
    Livewire::test(Create::class)
        ->set('usePathStyleEndpoint', true)
        ->set('endpointParts.host', 's3.oss-ap-southeast-1.aliyuncs.com')
        ->assertSet('usePathStyleEndpoint', true);
});
