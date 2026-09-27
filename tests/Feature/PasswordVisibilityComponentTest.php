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

it('renders env var password input before visibility toggle in tab order', function () {
    $html = Blade::render('<x-forms.env-var-input type="password" id="secret" />');

    expect(strpos($html, '<input'))->toBeLessThan(strpos($html, 'aria-label="Toggle password visibility"'));
});
