<?php

it('gates new cloud tokens and keys saved token rows by id', function () {
    $view = file_get_contents(__DIR__.'/../../resources/views/livewire/security/cloud-provider-tokens.blade.php');

    expect($view)->toContain("@can('create', App\\Models\\CloudProviderToken::class)")
        ->and($view)->toContain('wire:key="cloud-token-{{ $savedToken->id }}"');
});

it('gates the server cloud token action behind the update permission', function () {
    $serverTokenView = file_get_contents(__DIR__.'/../../resources/views/livewire/server/cloud-provider-token/show.blade.php');

    expect($serverTokenView)->toContain('<x-forms.button canGate="update" :canResource="$server"');
});
