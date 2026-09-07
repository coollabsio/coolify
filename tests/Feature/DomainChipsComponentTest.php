<?php

test('edit domain dialogs close after saving and omit redundant cancel actions', function () {
    foreach ([
        resource_path('views/livewire/project/application/domains.blade.php'),
        resource_path('views/livewire/project/service/domains.blade.php'),
    ] as $viewPath) {
        $view = file_get_contents($viewPath);

        expect($view)
            ->toContain('@edit-domain-saved.window="closeEditDomain()"')
            ->toContain('<x-forms.button type="submit" wire:target="updateDomain" isHighlighted>')
            ->not->toContain("@click=\"closeEditDomain()\">\n                                        Cancel");
    }
});

it('provides a reusable domain chips form component', function () {
    $component = file_get_contents(resource_path('views/components/forms/domain-chips.blade.php'));

    expect($component)
        ->toContain('chip-input')
        ->toContain('@entangle($model)')
        ->toContain('class="chip"')
        ->toContain('chip-remove');
});

it('uses domain chips for application public access and compose services', function () {
    $blade = file_get_contents(resource_path('views/livewire/project/application/general.blade.php'));

    expect($blade)
        ->toContain('x-forms.domain-chips')
        ->toContain('model="fqdn"')
        ->toContain(':model="$composeDomainKey"')
        ->not->toContain('id="parsedServiceDomains.');
});

it('uses domain chips for service application domains and edit domain modal', function () {
    $serviceIndex = file_get_contents(resource_path('views/livewire/project/service/index.blade.php'));
    $editDomain = file_get_contents(resource_path('views/livewire/project/service/edit-domain.blade.php'));

    expect($serviceIndex)
        ->toContain('x-forms.domain-chips')
        ->toContain('model="fqdn"')
        ->toContain('Make publicly available')
        ->toContain('enablePublicAccess')
        ->toContain('disablePublicAccess')
        ->toContain("x-bind:disabled=\"!String(port ?? '').trim()\"")
        ->toContain('sm:flex-row sm:items-end')
        ->not->toContain('label="Make it publicly available"')
        // Nested @if inside <x-forms.button> breaks Blade component compilation (ParseError endif).
        ->not->toContain('@if ($canTogglePublicAccess)');

    expect($editDomain)
        ->toContain('x-forms.domain-chips')
        ->toContain('model="fqdn"')
        ->not->toContain('id="fqdn"');
});

it('styles icon buttons with a visible hover state', function () {
    $utilities = file_get_contents(resource_path('css/utilities.css'));
    $resourceCard = file_get_contents(resource_path('views/livewire/project/service/resource-card.blade.php'));

    expect($utilities)
        ->toContain('@utility icon-button')
        ->toContain('hover:bg-neutral-100')
        ->toContain('dark:hover:bg-white/[0.07]');

    expect($resourceCard)
        ->toContain('class="icon-button"')
        ->toContain('title="Manage domains"');
});

it('exposes enable and disable public access methods on service index', function () {
    $source = file_get_contents(app_path('Livewire/Project/Service/Index.php'));

    expect($source)
        ->toContain('public function enablePublicAccess(): void')
        ->toContain('public function disablePublicAccess(): void')
        ->toContain('$this->instantSave()');
});
