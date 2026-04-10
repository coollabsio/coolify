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
        ->not->toContain('changePasswordFieldType');
});

it('renders password textarea with Alpine-managed visibility state', function () {
    $html = Blade::render('<x-forms.textarea type="password" id="secret" />');

    expect($html)
        ->toContain('@success.window="type = \'password\'"')
        ->toContain("x-data=\"{ type: 'password' }\"")
        ->toContain("x-on:click=\"type = type === 'password' ? 'text' : 'password'\"")
        ->not->toContain('changePasswordFieldType');
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
        ->toContain('x-bind:type="type"');
});
