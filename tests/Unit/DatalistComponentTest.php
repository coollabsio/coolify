<?php

use App\View\Components\Forms\Datalist;

it('renders with default properties', function () {
    $component = new Datalist;

    expect($component->required)->toBeFalse()
        ->and($component->disabled)->toBeFalse()
        ->and($component->readonly)->toBeFalse()
        ->and($component->multiple)->toBeFalse()
        ->and($component->instantSave)->toBeFalse()
        ->and($component->defaultClass)->toBe('input');
});

it('uses provided id', function () {
    $component = new Datalist(id: 'test-datalist');

    expect($component->id)->toBe('test-datalist');
});

it('accepts multiple selection mode', function () {
    $component = new Datalist(multiple: true);

    expect($component->multiple)->toBeTrue();
});

it('accepts instantSave parameter', function () {
    $component = new Datalist(instantSave: 'customSave');

    expect($component->instantSave)->toBe('customSave');
});

it('accepts placeholder', function () {
    $component = new Datalist(placeholder: 'Select an option...');

    expect($component->placeholder)->toBe('Select an option...');
});

it('accepts autofocus', function () {
    $component = new Datalist(autofocus: true);

    expect($component->autofocus)->toBeTrue();
});

it('accepts disabled state', function () {
    $component = new Datalist(disabled: true);

    expect($component->disabled)->toBeTrue();
});

it('accepts readonly state', function () {
    $component = new Datalist(readonly: true);

    expect($component->readonly)->toBeTrue();
});

it('accepts authorization properties', function () {
    $component = new Datalist(
        canGate: 'update',
        canResource: 'resource',
        autoDisable: false
    );

    expect($component->canGate)->toBe('update')
        ->and($component->canResource)->toBe('resource')
        ->and($component->autoDisable)->toBeFalse();
});
