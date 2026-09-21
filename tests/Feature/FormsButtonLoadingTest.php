<?php

use Illuminate\Support\Facades\Blade;

it('marks error buttons with the shared error class', function () {
    $html = Blade::render('<x-forms.button isError>Delete service</x-forms.button>');

    expect($html)
        ->toContain('class="button button-error"')
        ->not->toContain('isError');
});

it('keeps unavailable destructive actions styled as error buttons', function () {
    $view = file_get_contents(resource_path('views/livewire/project/shared/danger.blade.php'));

    expect($view)->toContain('<x-forms.button isError disabled tooltip="You do not have permission to delete this resource.">');
});

it('uses the shared error style for destructive form buttons', function (string $view, string $button) {
    expect(file_get_contents(resource_path("views/{$view}")))->toContain($button);
})->with([
    'unavailable team deletion' => [
        'livewire/team/danger-zone.blade.php',
        '<x-forms.button isError disabled tooltip="Resolve the requirements shown before deleting this team.">',
    ],
    'tag removal' => [
        'livewire/project/shared/tags.blade.php',
        '<x-forms.button isError wire:click="deleteTag',
    ],
]);

it('disables the button and targets the wire click action while loading', function () {
    $html = Blade::render(
        '<x-forms.button wire:click.prevent="checkConnection"><x-reicon name="refresh" class="size-3.5" />Check connection</x-forms.button>'
    );

    expect($html)
        ->toContain('wire:loading.attr="disabled"')
        ->toContain('wire:loading.class="is-loading"')
        ->toContain('wire:target="checkConnection"')
        ->toContain('wire:loading')
        ->toContain('animate-spin')
        ->toContain('Check connection');
});

it('uses an explicit wire target for submit buttons without wire click', function () {
    $html = Blade::render(
        '<x-forms.button type="submit" wire:target="addToken">Validate and add</x-forms.button>'
    );

    expect($html)
        ->toContain('type="submit"')
        ->toContain('wire:loading.attr="disabled"')
        ->toContain('wire:loading.class="is-loading"')
        ->toContain('wire:target="addToken"')
        ->toContain('animate-spin')
        ->toContain('Validate and add');
});

it('targets the S3 connection validation submit action', function () {
    $view = file_get_contents(resource_path('views/livewire/storage/create.blade.php'));

    expect($view)->toContain('<x-forms.button class="mt-4" type="submit" wire:target="submit">');
});

it('keeps method arguments when deriving the loading target', function () {
    $html = Blade::render(
        '<x-forms.button wire:click="setPrivateKey(42)">Use this key</x-forms.button>'
    );

    expect($html)
        ->toContain('wire:target="setPrivateKey(42)"')
        ->toContain('wire:loading.attr="disabled"')
        ->toContain('wire:loading.class="is-loading"');
});

it('skips loading attributes when the loading indicator is disabled', function () {
    $html = Blade::render(
        '<x-forms.button wire:click="checkConnection" :showLoadingIndicator="false">Check connection</x-forms.button>'
    );

    expect($html)
        ->not->toContain('wire:loading.attr="disabled"')
        ->not->toContain('wire:loading.class="is-loading"')
        ->not->toContain('animate-spin')
        ->toContain('Check connection');
});

it('hides static button icons with the shared is-loading utility', function () {
    $css = file_get_contents(resource_path('css/utilities.css'));

    expect($css)->toContain('.button.is-loading > svg:not(.animate-spin)');
});

it('applies automatic loading behavior on the server private key check connection button', function () {
    $view = file_get_contents(resource_path('views/livewire/server/private-key/show.blade.php'));

    expect($view)
        ->toContain('wire:click.prevent="checkConnection"')
        ->toContain('<x-reicon name="refresh" class="size-3.5" />')
        ->toContain('<x-forms.button canGate="update" :canResource="$server"');
});
