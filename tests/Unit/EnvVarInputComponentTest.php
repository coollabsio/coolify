<?php

use App\View\Components\Forms\EnvVarInput;

it('renders with default properties', function () {
    $component = new EnvVarInput;

    expect($component->required)->toBeFalse()
        ->and($component->disabled)->toBeFalse()
        ->and($component->readonly)->toBeFalse()
        ->and($component->defaultClass)->toBe('input')
        ->and($component->availableVars)->toBe([]);
});

it('uses provided id', function () {
    $component = new EnvVarInput(id: 'env-key');

    expect($component->id)->toBe('env-key');
});

it('accepts available vars', function () {
    $vars = [
        'team' => ['DATABASE_URL', 'API_KEY'],
        'project' => ['STRIPE_KEY'],
        'environment' => ['DEBUG'],
    ];

    $component = new EnvVarInput(availableVars: $vars);

    expect($component->availableVars)->toBe($vars);
});

it('accepts required parameter', function () {
    $component = new EnvVarInput(required: true);

    expect($component->required)->toBeTrue();
});

it('accepts disabled state', function () {
    $component = new EnvVarInput(disabled: true);

    expect($component->disabled)->toBeTrue();
});

it('accepts readonly state', function () {
    $component = new EnvVarInput(readonly: true);

    expect($component->readonly)->toBeTrue();
});

it('accepts autofocus parameter', function () {
    $component = new EnvVarInput(autofocus: true);

    expect($component->autofocus)->toBeTrue();
});

it('accepts authorization properties', function () {
    $component = new EnvVarInput(
        canGate: 'update',
        canResource: 'resource',
        autoDisable: false
    );

    expect($component->canGate)->toBe('update')
        ->and($component->canResource)->toBe('resource')
        ->and($component->autoDisable)->toBeFalse();
});
