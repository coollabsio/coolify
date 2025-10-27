<?php

use App\Livewire\Project\Service\EditCompose;
use App\Models\Service;
use Livewire\Livewire;
use Mockery;

beforeEach(function () {
    $this->serviceMock = Mockery::mock(Service::class);
    $this->serviceMock->shouldReceive('ownedByCurrentTeam->find')
        ->andReturn($this->serviceMock);
    $this->serviceMock->shouldReceive('getAttribute')
        ->with('docker_compose_raw')
        ->andReturn('version: "3.8"\nservices:\n  app:\n    image: nginx');
    $this->serviceMock->shouldReceive('getAttribute')
        ->with('docker_compose')
        ->andReturn('version: "3.8"\nservices:\n  app:\n    image: nginx');
    $this->serviceMock->shouldReceive('getAttribute')
        ->with('is_container_label_escape_enabled')
        ->andReturn(false);
    $this->serviceMock->shouldReceive('getAttribute')
        ->with('server_id')
        ->andReturn(1);
});

afterEach(function () {
    Mockery::close();
});

it('has saveEditedCompose method accessible', function () {
    $component = new EditCompose;
    expect(method_exists($component, 'saveEditedCompose'))->toBeTrue();
});

it('can call saveEditedCompose method', function () {
    // Mock the Service model query
    Service::shouldReceive('ownedByCurrentTeam->find')
        ->andReturn($this->serviceMock);

    // Create and test the component
    $component = Livewire::test(EditCompose::class, [
        'serviceId' => 1,
    ]);

    // Test that the method exists and can be called
    $component->call('saveEditedCompose')
        ->assertDispatched('info', 'Saving new docker compose...')
        ->assertDispatched('saveCompose')
        ->assertDispatched('refreshStorages');
});
