<?php

use App\Livewire\Project\Shared\EnvironmentVariable\Show;
use App\Models\EnvironmentVariable;

afterEach(function () {
    Mockery::close();
});

test('SERVICE_FQDN variables are identified as magic variables', function () {
    $mock = Mockery::mock(EnvironmentVariable::class);
    $mock->shouldReceive('getAttribute')
        ->with('key')
        ->andReturn('SERVICE_FQDN_DB');
    $mock->shouldReceive('getAttribute')
        ->with('is_shown_once')
        ->andReturn(false);
    $mock->shouldReceive('getMorphClass')
        ->andReturn(EnvironmentVariable::class);

    $component = new Show;
    $component->env = $mock;
    $component->checkEnvs();

    expect($component->isMagicVariable)->toBeTrue();
    expect($component->isDisabled)->toBeTrue();
});

test('SERVICE_URL variables are identified as magic variables', function () {
    $mock = Mockery::mock(EnvironmentVariable::class);
    $mock->shouldReceive('getAttribute')
        ->with('key')
        ->andReturn('SERVICE_URL_API');
    $mock->shouldReceive('getAttribute')
        ->with('is_shown_once')
        ->andReturn(false);
    $mock->shouldReceive('getMorphClass')
        ->andReturn(EnvironmentVariable::class);

    $component = new Show;
    $component->env = $mock;
    $component->checkEnvs();

    expect($component->isMagicVariable)->toBeTrue();
    expect($component->isDisabled)->toBeTrue();
});

test('SERVICE_NAME variables are identified as magic variables', function () {
    $mock = Mockery::mock(EnvironmentVariable::class);
    $mock->shouldReceive('getAttribute')
        ->with('key')
        ->andReturn('SERVICE_NAME');
    $mock->shouldReceive('getAttribute')
        ->with('is_shown_once')
        ->andReturn(false);
    $mock->shouldReceive('getMorphClass')
        ->andReturn(EnvironmentVariable::class);

    $component = new Show;
    $component->env = $mock;
    $component->checkEnvs();

    expect($component->isMagicVariable)->toBeTrue();
    expect($component->isDisabled)->toBeTrue();
});

test('regular variables are not magic variables', function () {
    $mock = Mockery::mock(EnvironmentVariable::class);
    $mock->shouldReceive('getAttribute')
        ->with('key')
        ->andReturn('DATABASE_URL');
    $mock->shouldReceive('getAttribute')
        ->with('is_shown_once')
        ->andReturn(false);
    $mock->shouldReceive('getMorphClass')
        ->andReturn(EnvironmentVariable::class);

    $component = new Show;
    $component->env = $mock;
    $component->checkEnvs();

    expect($component->isMagicVariable)->toBeFalse();
    expect($component->isDisabled)->toBeFalse();
});

test('locked variables are not magic variables unless they start with SERVICE_', function () {
    $mock = Mockery::mock(EnvironmentVariable::class);
    $mock->shouldReceive('getAttribute')
        ->with('key')
        ->andReturn('SECRET_KEY');
    $mock->shouldReceive('getAttribute')
        ->with('is_shown_once')
        ->andReturn(true);
    $mock->shouldReceive('getMorphClass')
        ->andReturn(EnvironmentVariable::class);

    $component = new Show;
    $component->env = $mock;
    $component->checkEnvs();

    expect($component->isMagicVariable)->toBeFalse();
    expect($component->isLocked)->toBeTrue();
});

test('SERVICE_FQDN with port suffix is identified as magic variable', function () {
    $mock = Mockery::mock(EnvironmentVariable::class);
    $mock->shouldReceive('getAttribute')
        ->with('key')
        ->andReturn('SERVICE_FQDN_DB_5432');
    $mock->shouldReceive('getAttribute')
        ->with('is_shown_once')
        ->andReturn(false);
    $mock->shouldReceive('getMorphClass')
        ->andReturn(EnvironmentVariable::class);

    $component = new Show;
    $component->env = $mock;
    $component->checkEnvs();

    expect($component->isMagicVariable)->toBeTrue();
    expect($component->isDisabled)->toBeTrue();
});

test('SERVICE_URL with port suffix is identified as magic variable', function () {
    $mock = Mockery::mock(EnvironmentVariable::class);
    $mock->shouldReceive('getAttribute')
        ->with('key')
        ->andReturn('SERVICE_URL_API_8080');
    $mock->shouldReceive('getAttribute')
        ->with('is_shown_once')
        ->andReturn(false);
    $mock->shouldReceive('getMorphClass')
        ->andReturn(EnvironmentVariable::class);

    $component = new Show;
    $component->env = $mock;
    $component->checkEnvs();

    expect($component->isMagicVariable)->toBeTrue();
    expect($component->isDisabled)->toBeTrue();
});
