<?php

it('allows selecting Vultr in cloud provider token forms', function () {
    $view = file_get_contents(__DIR__.'/../../resources/views/livewire/security/cloud-provider-token-form.blade.php');
    $component = file_get_contents(__DIR__.'/../../app/Livewire/Security/CloudProviderTokenForm.php');

    expect($view)->toContain('<x-forms.select required id="provider" label="Provider" wire:model.live="provider">')
        ->and($view)->toContain('<option value="vultr">Vultr</option>')
        ->and($view)->toContain('<option value="digitalocean">DigitalOcean</option>')
        ->and(substr_count($view, 'Open Account → API Access.'))->toBe(2)
        ->and(substr_count($view, 'https://console.vultr.com/user/apiaccess/'))->toBe(2)
        ->and($view)->not->toContain('cloudProviderTokens->where(\'provider\', $provider)->isEmpty()')
        ->and($view)->not->toContain('<x-forms.select required id="provider" label="Provider" disabled>')
        ->and($component)->toContain("'provider' => 'required|string|in:hetzner,digitalocean,vultr'");
});

it('keeps provider affiliate links on server provider views', function () {
    $tokenFormView = file_get_contents(__DIR__.'/../../resources/views/livewire/security/cloud-provider-token-form.blade.php');
    $hetznerView = file_get_contents(__DIR__.'/../../resources/views/livewire/server/new/by-hetzner.blade.php');
    $vultrView = file_get_contents(__DIR__.'/../../resources/views/livewire/server/new/by-vultr.blade.php');
    $digitalOceanView = file_get_contents(__DIR__.'/../../resources/views/livewire/server/new/by-digital-ocean.blade.php');

    expect($tokenFormView)
        ->not->toContain('https://coolify.io/hetzner')
        ->not->toContain('https://coolify.io/vultr')
        ->and($hetznerView)->toContain('https://coolify.io/hetzner')
        ->and($vultrView)->toContain('https://coolify.io/vultr')
        ->and($digitalOceanView)->toContain('https://coolify.io/digitalocean');
});

it('uses a provider dropdown and modal forms on cloud provider tokens page', function () {
    $view = file_get_contents(__DIR__.'/../../resources/views/livewire/security/cloud-provider-tokens.blade.php');

    expect($view)->toContain('+ Add')
        ->and($view)->toContain('<div class="flex items-center gap-2">')
        ->and($view)->toContain('<div class="grid gap-4 lg:grid-cols-2">')
        ->and($view)->toContain('class="coolbox group"')
        ->and($view)->toContain('<div class="box-title">')
        ->and($view)->toContain('<div class="box-description">')
        ->and($view)->toContain('<x-forms.button isHighlighted @click="dropdownOpen = !dropdownOpen" type="button">')
        ->and($view)->toContain('Add Hetzner Token')
        ->and($view)->toContain('Add DigitalOcean Token')
        ->and($view)->toContain('Add Vultr Token')
        ->and($view)->toContain('<livewire:security.cloud-provider-token-form :modal_mode="true" provider="hetzner"')
        ->and($view)->toContain('<livewire:security.cloud-provider-token-form :modal_mode="true" provider="digitalocean"')
        ->and($view)->toContain('<livewire:security.cloud-provider-token-form :modal_mode="true" provider="vultr"')
        ->and($view)->not->toContain('<h3>New Token</h3>')
        ->and($view)->not->toContain(':modal_mode="false"')
        ->and($view)->not->toContain('wire:click="validateToken')
        ->and($view)->not->toContain('submitAction="deleteToken')
        ->and($view)->not->toContain('Created {{ $savedToken->created_at->diffForHumans() }}');
});

it('shows explicit loading spinners and disables cloud token action buttons while requests run', function () {
    $tokenFormView = file_get_contents(__DIR__.'/../../resources/views/livewire/security/cloud-provider-token-form.blade.php');
    $tokenShowView = file_get_contents(__DIR__.'/../../resources/views/livewire/security/cloud-provider-token/show.blade.php');
    $serverTokenView = file_get_contents(__DIR__.'/../../resources/views/livewire/server/cloud-provider-token/show.blade.php');

    expect(substr_count($tokenFormView, 'wire:loading.attr="disabled" wire:target="addToken"'))->toBe(2)
        ->and(substr_count($tokenFormView, '<x-loading-on-button wire:loading wire:target="addToken" />'))->toBe(2)
        ->and($tokenShowView)->toContain('wire:loading.attr="disabled" wire:target="validateToken"')
        ->and($tokenShowView)->toContain('<x-loading-on-button wire:loading wire:target="validateToken" />')
        ->and($serverTokenView)->toContain('wire:loading.attr="disabled"')
        ->and($serverTokenView)->toContain('wire:target="validateToken"')
        ->and($serverTokenView)->toContain('<x-loading-on-button wire:loading wire:target="validateToken" />');
});
