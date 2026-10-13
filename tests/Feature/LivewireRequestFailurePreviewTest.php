<?php

use App\Livewire\Dev\LivewireRequestFailurePreview;
use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::forceCreate(['id' => 0]);
});

it('registers the Livewire request failure preview in testing', function () {
    expect(Route::has('dev.livewire-request-failure-preview'))->toBeTrue();

    $this->get('/__livewire-request-failure')
        ->assertSuccessful()
        ->assertSee('Livewire request failure preview')
        ->assertSee('Gateway timeout')
        ->assertSee('504');
});

it('returns proxy-style html for supported statuses', function () {
    Livewire::test(LivewireRequestFailurePreview::class)
        ->call('fail', 504)
        ->assertStatus(504)
        ->assertContent('<!doctype html><html><body><h1>Gateway time-out</h1><p>cloudflare proxy error 504</p></body></html>');
});

it('rejects statuses outside the supported list', function () {
    Livewire::test(LivewireRequestFailurePreview::class)
        ->call('fail', 500)
        ->assertStatus(404);
});
