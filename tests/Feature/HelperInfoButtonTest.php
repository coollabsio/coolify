<?php

use Illuminate\Support\Facades\Blade;

test('helper trigger is a button that stops label activation', function () {
    $path = resource_path('views/components/helper.blade.php');
    $contents = file_get_contents($path);

    expect($contents)
        ->toContain('type="button"')
        ->toContain('@click.prevent.stop')
        ->toContain('aria-label="{{ $label }}"')
        ->toContain('data-icon-tooltip-ignore')
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

test('clicking a helper does not pin its popup open', function () {
    $helper = file_get_contents(resource_path('views/components/helper.blade.php'));

    expect($helper)
        ->toContain('@click.prevent.stop="show()"')
        ->not->toContain('pinned');
});

test('helper popup stays in its alpine scope during livewire morphs', function () {
    $helper = file_get_contents(resource_path('views/components/helper.blade.php'));

    expect($helper)
        ->not->toContain('x-teleport');
});

test('helper triggers do not create stacking contexts above open tooltips', function () {
    $helper = file_get_contents(resource_path('views/components/helper.blade.php'));

    expect($helper)
        ->toContain("'class' => 'relative inline-block align-middle'")
        ->toContain("'info-helper relative inline-flex")
        ->not->toContain("'class' => 'relative z-10 inline-block align-middle'")
        ->not->toContain("'info-helper relative z-10 inline-flex");
});

test('helper popup prefers a position below its trigger and falls back above', function () {
    $helper = file_get_contents(resource_path('views/components/helper.blade.php'));

    expect($helper)
        ->toContain('let top = triggerRect.bottom + padding;')
        ->toContain('let left = triggerRect.right - popupRect.width;')
        ->toContain('top = triggerRect.top - popupRect.height - padding;');
});

test('helper popup stays within the visual viewport on mobile', function () {
    $helper = file_get_contents(resource_path('views/components/helper.blade.php'));

    expect($helper)
        ->toContain('window.visualViewport')
        ->toContain('viewport?.offsetLeft')
        ->toContain('viewport?.offsetTop')
        ->toContain('max-width: ${popupWidth}px;')
        ->toContain('max-height: ${availableHeight}px;')
        ->toContain('overflow-y: auto;');
});

test('helper popup width is capped while allowing content to wrap vertically', function () {
    $helper = file_get_contents(resource_path('views/components/helper.blade.php'));

    expect($helper)
        ->toContain('const maxPopupWidth = 320;')
        ->toContain('const popupWidth = Math.min(maxPopupWidth, availableWidth);')
        ->toContain('max-width: ${popupWidth}px;')
        ->toContain('whitespace-normal');
});

test('helper popup supports focus dismissal and tooltip aria relationships', function () {
    $helper = file_get_contents(resource_path('views/components/helper.blade.php'));

    expect($helper)
        ->toContain('closeWhenFocusLeaves()')
        ->toContain('@focus="show()"')
        ->toContain('@blur="closeWhenFocusLeaves()"')
        ->toContain('role="tooltip"')
        ->toContain(':aria-describedby="open ? $id(\'helper-popup\') : null"')
        ->toContain(':id="$id(\'helper-popup\')"');
});

test('teleported helper popup closes from taps outside on mobile', function () {
    $helper = file_get_contents(resource_path('views/components/helper.blade.php'));

    expect($helper)
        ->toContain('@pointerdown.window="closeWhenPointerIsOutside($event)"')
        ->toContain('closeWhenPointerIsOutside(event)')
        ->toContain('this.$refs.popup?.contains(event.target)');
});

test('button tooltips are available to keyboard and assistive technology users', function () {
    $button = file_get_contents(resource_path('views/components/forms/button.blade.php'));

    expect($button)
        ->toContain('@focusin="showTooltip()"')
        ->toContain('@focusout="hideTooltip()"')
        ->toContain('@click.outside="hideTooltip()"')
        ->toContain('role="tooltip"')
        ->toContain(':aria-describedby="visible ? $id(\'button-tooltip\') : null"')
        ->toContain(':id="$id(\'button-tooltip\')"');
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
