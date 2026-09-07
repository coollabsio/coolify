<?php

use App\Livewire\Storage\Create;

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
