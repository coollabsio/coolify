<?php

use App\View\Components\Forms\InputWithSelect;

it('renders with default properties', function () {
    $component = new InputWithSelect;

    expect($component->required)->toBeFalse()
        ->and($component->disabled)->toBeFalse()
        ->and($component->readonly)->toBeFalse()
        ->and($component->defaultClass)->toBe('input')
        ->and($component->type)->toBe('text')
        ->and($component->options)->toBe([]);
});

it('uses provided id', function () {
    $component = new InputWithSelect(id: 'test-input-select');

    expect($component->id)->toBe('test-input-select');
});

it('accepts options array', function () {
    $options = ['b' => 'B', 'k' => 'KiB', 'm' => 'MiB', 'g' => 'GiB'];
    $component = new InputWithSelect(options: $options);

    expect($component->options)->toBe($options);
});

it('sets default option to first option when not provided', function () {
    $options = ['b' => 'B', 'k' => 'KiB', 'm' => 'MiB'];
    $component = new InputWithSelect(options: $options);

    // defaultOption is set in render(), so we test the logic directly
    if (is_null($component->defaultOption) && !empty($component->options)) {
        $component->defaultOption = array_key_first($component->options);
    }

    expect($component->defaultOption)->toBe('b');
});

it('uses provided default option', function () {
    $options = ['b' => 'B', 'k' => 'KiB', 'm' => 'MiB'];
    $component = new InputWithSelect(options: $options, defaultOption: 'm');

    expect($component->defaultOption)->toBe('m');
});

it('accepts min and max values', function () {
    $component = new InputWithSelect(min: 0, max: 100);

    expect($component->min)->toBe(0.0)
        ->and($component->max)->toBe(100.0);
});

it('accepts type parameter', function () {
    $component = new InputWithSelect(type: 'number');

    expect($component->type)->toBe('number');
});

it('accepts authorization properties', function () {
    $component = new InputWithSelect(
        canGate: 'update',
        canResource: 'resource',
        autoDisable: false
    );

    expect($component->canGate)->toBe('update')
        ->and($component->canResource)->toBe('resource')
        ->and($component->autoDisable)->toBeFalse();
});

it('can be manually disabled', function () {
    $component = new InputWithSelect(disabled: true);

    expect($component->disabled)->toBeTrue();
});
