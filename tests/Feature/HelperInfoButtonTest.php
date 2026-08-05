<?php

use Illuminate\Support\Facades\Blade;

test('helper trigger is a button that stops label activation', function () {
    $path = resource_path('views/components/helper.blade.php');
    $contents = file_get_contents($path);

    expect($contents)
        ->toContain('type="button"')
        ->toContain('@click.prevent.stop')
        ->toContain('aria-label="More information"')
        ->toContain('info-helper-popup')
        ->toContain('name="info-circle"')
        ->toContain('class="size-3.5 text-neutral-400')
        ->not->toContain('<div x-ref="trigger" class="info-helper"');
});

test('helper popup uses the redesigned raised surface styles', function () {
    $helper = file_get_contents(resource_path('views/components/helper.blade.php'));
    $utilities = file_get_contents(resource_path('css/utilities.css'));

    expect($helper)
        ->toContain('info-helper-popup')
        ->toContain('x-transition:enter')
        ->toContain('text-[13px] leading-5');

    expect($utilities)
        ->toContain('@utility info-helper-popup')
        ->toContain('dark:bg-raised')
        ->toContain('dark:border-white/10')
        ->toContain('shadow-modal')
        ->toContain('@utility auth-tooltip')
        ->toContain('rounded-lg');
});

test('helper popup remains open while moving from the trigger into interactive content', function () {
    $helper = file_get_contents(resource_path('views/components/helper.blade.php'));

    expect($helper)
        ->toContain('hideTimer: null')
        ->toContain('setTimeout(() =>')
        ->toContain('@mouseenter="cancelHide()"')
        ->toContain('@mouseleave="hide()"');
});

test('listbox keeps the helper outside the label association', function () {
    $path = resource_path('views/components/forms/listbox.blade.php');
    $contents = file_get_contents($path);

    expect($contents)
        ->toContain('Keep helper outside the label')
        ->toContain('<div class="mb-1.5 flex h-4 w-full items-center gap-1.5">')
        ->toContain('class="mb-0! flex items-center gap-1.5 leading-4"')
        ->toMatch('/<label for="\{\{ \$id \}\}-trigger"[^>]*>[\s\S]*?<\/label>\s*@if \(\$helper\)/');
});

test('helper popup renders when the component is used next to a listbox label', function () {
    $html = Blade::render(<<<'BLADE'
        <x-forms.listbox id="serverTimezone" label="Server timezone"
            helper="Used for backup schedules." :options="[
                ['value' => 'UTC', 'label' => 'UTC'],
            ]" :wire="false" value="UTC" />
    BLADE);

    expect($html)
        ->toContain('Server timezone')
        ->toContain('Used for backup schedules.')
        ->toContain('type="button"')
        ->toContain('aria-label="More information"')
        ->toContain('serverTimezone-trigger')
        // Helper button must not be nested inside the label[for=trigger] element.
        ->not->toMatch('/<label[^>]*for="serverTimezone-trigger"[^>]*>[\s\S]*aria-label="More information"[\s\S]*<\/label>/');
});
