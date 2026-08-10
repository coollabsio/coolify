<?php

use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

beforeEach(function () {
    $errors = new ViewErrorBag;
    $errors->put('default', new MessageBag);
    view()->share('errors', $errors);
});

it('renders password input with Alpine-managed visibility state', function () {
    $html = Blade::render('<x-forms.input type="password" id="secret" />');

    expect($html)
        ->toContain('@success.window="type = \'password\'"')
        ->toContain("x-data=\"{ type: 'password' }\"")
        ->toContain("x-on:click=\"type = type === 'password' ? 'text' : 'password'\"")
        ->toContain('x-bind:type="type"')
        ->toContain("x-bind:class=\"{ 'truncate': type === 'text' && ! \$el.disabled }\"")
        ->toContain('input-with-password-toggle')
        ->toContain('password-toggle')
        ->toContain('z-10')
        // Visible state uses reicon eye-off2 (distinct path start).
        ->toContain("x-show=\"type === 'text'\"")
        ->toContain('M2.53033 1.46967')
        ->not->toContain('changePasswordFieldType');
});

it('renders password input before visibility toggle in tab order', function () {
    $html = Blade::render('<x-forms.input type="password" id="secret" />');

    expect(strpos($html, '<input'))->toBeLessThan(strpos($html, 'aria-label="Toggle password visibility"'));
});

it('does not add toggle clearance when allowToPeak is disabled', function () {
    $html = Blade::render('<x-forms.input type="password" id="secret" :allow-to-peak="false" />');

    expect($html)
        ->not->toContain('input-with-password-toggle')
        ->not->toContain('aria-label="Toggle password visibility"');
});

it('keeps password toggle padding above settings-workspace input overrides', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toContain('.input.input-with-password-toggle')
        ->toContain('.application-settings-workspace .input.input-with-password-toggle')
        ->toContain('.application-settings-form .input.input-with-password-toggle')
        ->toContain('padding-right: 2.5rem');
});

it('renders password textarea with Alpine-managed visibility state', function () {
    $html = Blade::render('<x-forms.textarea type="password" id="secret" />');

    expect($html)
        ->toContain('@success.window="type = \'password\'"')
        ->toContain("x-data=\"{ type: 'password' }\"")
        ->toContain("x-on:click=\"type = type === 'password' ? 'text' : 'password'\"")
        ->not->toContain('changePasswordFieldType');
});

it('renders password textarea input before visibility toggle in tab order', function () {
    $html = Blade::render('<x-forms.textarea type="password" id="secret" />');

    expect(strpos($html, '<input'))->toBeLessThan(strpos($html, 'aria-label="Toggle password visibility"'));
});

it('renders textarea without monospace classes by default', function () {
    $html = Blade::render('<x-forms.textarea id="notes" />');

    expect($html)
        ->toContain('class="input scrollbar"')
        ->not->toContain('font-mono');
});

it('renders textarea with monospace classes when requested', function () {
    $html = Blade::render('<x-forms.textarea id="variables" monospace />');

    expect($html)->toContain('class="input scrollbar font-mono"');
});

it('resets password visibility on success event for env-var-input', function () {
    $html = Blade::render('<x-forms.env-var-input type="password" id="secret" />');

    expect($html)
        ->toContain("@success.window=\"type = 'password'\"")
        ->toContain("x-on:click=\"type = type === 'password' ? 'text' : 'password'\"")
        ->toContain('x-bind:type="type"')
        ->toContain('input-with-password-toggle')
        ->toContain('password-toggle')
        ->toContain('M2.53033 1.46967');
});

it('registers the eye-off2 reicon used when password value is visible', function () {
    $path = resource_path('views/components/reicon.blade.php');
    $contents = file_get_contents($path);

    expect($contents)->toContain("'eye-off2' => ");

    $html = Blade::render('<x-reicon name="eye-off2" class="size-[18px]" />');

    expect($html)
        ->toContain('viewBox="0 0 24 24"')
        ->toContain('fill="currentColor"')
        ->toContain('M2.53033 1.46967');
});

it('uses the same eye-off2 icon in configuration changes expand toggle', function () {
    $html = view('components.deployment.configuration-diff', [
        'diff' => [
            'changes' => [
                [
                    'key' => 'env.SECRET',
                    'section_label' => 'Environment',
                    'label' => 'SECRET',
                    'expandable' => true,
                    'old_display_value' => 'old-***',
                    'new_display_value' => 'new-***',
                    'old_full_value' => 'old-secret-value',
                    'new_full_value' => 'new-secret-value',
                ],
            ],
        ],
    ])->render();

    // eye-off2 path (password inputs); not the older eye-off glyph.
    expect($html)
        ->toContain('M2.53033 1.46967')
        ->not->toContain('M22.2954 6.31083');
});

it('uses a wide configuration changes dialog', function () {
    $view = file_get_contents(resource_path('views/livewire/project/shared/configuration-checker.blade.php'));

    expect($view)->toContain('max-w-6xl');
});

it('renders env var password input before visibility toggle in tab order', function () {
    $html = Blade::render('<x-forms.env-var-input type="password" id="secret" />');

    expect(strpos($html, '<input'))->toBeLessThan(strpos($html, 'aria-label="Toggle password visibility"'));
});
