<?php

use App\Livewire\Project\Edit;
use App\Models\Project;

it('accepts valid hex color codes', function () {
    $component = Mockery::mock(Edit::class)->makePartial();
    $component->color = '#FF5733';
    $component->name = 'Test Project';
    $component->description = 'Test Description';

    $rules = $component->rules();

    expect($rules)->toHaveKey('color')
        ->and($rules['color'])->toContain('nullable')
        ->and($rules['color'])->toContain('string');
});

it('accepts null color value', function () {
    $component = Mockery::mock(Edit::class)->makePartial();
    $component->color = null;
    $component->name = 'Test Project';
    $component->description = 'Test Description';

    $rules = $component->rules();

    expect($rules['color'])->toContain('nullable');
});

it('color validation rules include regex pattern', function () {
    $component = Mockery::mock(Edit::class)->makePartial();

    $rules = $component->rules();

    expect($rules)
        ->toHaveKey('color')
        ->and($rules['color'])
        ->toBeArray()
        ->and(count($rules['color']))->toBe(3)
        ->and($rules['color'][0])->toBe('nullable')
        ->and($rules['color'][1])->toBe('string')
        ->and($rules['color'][2])->toBeString()
        ->and($rules['color'][2])->toContain('regex:/^#[0-9A-Fa-f]{6}$/');
});

it('has custom validation message for invalid color format', function () {
    $component = Mockery::mock(Edit::class)->makePartial();

    $messages = $component->messages();

    expect($messages)
        ->toHaveKey('color.regex')
        ->and($messages['color.regex'])
        ->toBeString()
        ->toContain('#FF5733');
});

it('syncs color from model to component', function () {
    $project = Mockery::mock(Project::class)->makePartial();
    $project->shouldReceive('getAttribute')
        ->with('name')
        ->andReturn('Test Project');
    $project->shouldReceive('getAttribute')
        ->with('description')
        ->andReturn('Test Description');
    $project->shouldReceive('getAttribute')
        ->with('color')
        ->andReturn('#FF5733');

    $project->name = 'Test Project';
    $project->description = 'Test Description';
    $project->color = '#FF5733';

    $component = Mockery::mock(Edit::class)->makePartial();
    $component->project = $project;

    $component->syncData(false);

    expect($component->color)->toBe('#FF5733');
});

it('syncs null color from model to component', function () {
    $project = Mockery::mock(Project::class)->makePartial();
    $project->shouldReceive('getAttribute')
        ->with('name')
        ->andReturn('Test Project');
    $project->shouldReceive('getAttribute')
        ->with('description')
        ->andReturn('Test Description');
    $project->shouldReceive('getAttribute')
        ->with('color')
        ->andReturn(null);

    $project->name = 'Test Project';
    $project->description = 'Test Description';
    $project->color = null;

    $component = Mockery::mock(Edit::class)->makePartial();
    $component->project = $project;

    $component->syncData(false);

    expect($component->color)->toBeNull();
});

it('syncs color from component to model', function () {
    $project = Mockery::mock(Project::class);
    $project->shouldReceive('update')
        ->once()
        ->with(Mockery::on(function ($data) {
            return $data['color'] === '#00FF00'
                && $data['name'] === 'Test Project'
                && $data['description'] === 'Test Description';
        }));

    $component = Mockery::mock(Edit::class)->makePartial();
    $component->project = $project;
    $component->name = 'Test Project';
    $component->description = 'Test Description';
    $component->color = '#00FF00';

    $component->shouldReceive('validate')->once();

    $component->syncData(true);
});

it('syncs null color from component to model', function () {
    $project = Mockery::mock(Project::class);
    $project->shouldReceive('update')
        ->once()
        ->with(Mockery::on(function ($data) {
            return $data['color'] === null
                && array_key_exists('color', $data);
        }));

    $component = Mockery::mock(Edit::class)->makePartial();
    $component->project = $project;
    $component->name = 'Test Project';
    $component->description = 'Test Description';
    $component->color = null;

    $component->shouldReceive('validate')->once();

    $component->syncData(true);
});
