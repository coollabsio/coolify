<?php

use App\Livewire\Project\Shared\EnvironmentVariable\Add;
use Livewire\Livewire;

it('rejects environment variable keys Docker cannot represent in the add form', function () {
    Livewire::test(Add::class)
        ->set('key', 'BAD=KEY')
        ->set('value', 'value')
        ->call('submit')
        ->assertHasErrors(['key' => 'regex']);
});

it('allows Docker-compatible environment variable keys in the add form', function (string $key) {
    Livewire::test(Add::class)
        ->set('key', $key)
        ->set('value', 'value')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched('saveKey', function ($event, array $data) use ($key) {
            return data_get($data, 'key') === $key || data_get($data, '0.key') === $key;
        });
})->with([
    'letters and underscore' => 'APP_ENV',
    'dot' => 'node.name',
    'uppercase dots' => 'XPACK.SECURITY.ENABLED',
]);

it('rejects environment variable keys that are not portable identifiers in the add form', function (string $key) {
    Livewire::test(Add::class)
        ->set('key', $key)
        ->set('value', 'value')
        ->call('submit')
        ->assertHasErrors(['key' => 'regex']);
})->with([
    'starts with digit' => '1BAD',
    'hyphen' => 'BAD-KEY',
    'command substitution' => 'BAD$(id)',
    'semicolon' => 'BAD;KEY',
]);

it('trims surrounding whitespace in environment variable keys in the add form', function () {
    Livewire::test(Add::class)
        ->set('key', ' node.name ')
        ->set('value', 'value')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched('saveKey', function ($event, array $data) {
            return data_get($data, 'key') === 'node.name' || data_get($data, '0.key') === 'node.name';
        });
});
