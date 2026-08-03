<?php

use App\Livewire\Project\EnvironmentEdit;
use App\Models\Environment;

it('accepts valid hex color codes for environments', function () {
    $component = Mockery::mock(EnvironmentEdit::class)->makePartial();

    $rules = $component->rules();

    expect($rules)
        ->toHaveKey('color')
        ->and($rules['color'])
        ->toBeArray()
        ->toContain('nullable')
        ->toContain('string')
        ->and($rules['color'][2])->toContain('regex:/^#[0-9A-Fa-f]{6}$/');
});

it('has custom validation message for invalid environment color format', function () {
    $component = Mockery::mock(EnvironmentEdit::class)->makePartial();

    $messages = $component->messages();

    expect($messages)
        ->toHaveKey('color.regex')
        ->and($messages['color.regex'])
        ->toContain('#FF5733');
});

it('syncs color from environment model to component', function () {
    $environment = Mockery::mock(Environment::class)->makePartial();
    $environment->name = 'Production';
    $environment->description = 'Production environment';
    $environment->color = '#FF5733';

    $component = Mockery::mock(EnvironmentEdit::class)->makePartial();
    $component->environment = $environment;

    $component->syncData(false);

    expect($component->color)->toBe('#FF5733');
});

it('syncs color from component to environment model', function () {
    $environment = Mockery::mock(Environment::class);
    $environment->shouldReceive('update')
        ->once()
        ->with(Mockery::on(function ($data) {
            return $data['color'] === '#00FF00'
                && $data['name'] === 'Production'
                && $data['description'] === 'Production environment';
        }));

    $component = Mockery::mock(EnvironmentEdit::class)->makePartial();
    $component->environment = $environment;
    $component->name = 'Production';
    $component->description = 'Production environment';
    $component->color = '#00FF00';

    $component->shouldReceive('validate')->once();

    $component->syncData(true);
});
