<?php

use App\Livewire\Storage\Create;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

describe('S3 Storage Endpoint Preservation', function () {
    test('preserves Cloudflare R2 EU jurisdiction endpoint', function () {
        $euEndpoint = 'https://b52bf1144d4001acd18a39a8f258f90b.eu.r2.cloudflarestorage.com';

        Livewire::test(Create::class)
            ->set('endpoint', $euEndpoint)
            ->call('updatedEndpoint', $euEndpoint)
            ->assertSet('endpoint', $euEndpoint);
    });

    test('preserves Cloudflare R2 standard endpoint', function () {
        $endpoint = 'https://b52bf1144d4001acd18a39a8f258f90b.r2.cloudflarestorage.com';

        Livewire::test(Create::class)
            ->set('endpoint', $endpoint)
            ->call('updatedEndpoint', $endpoint)
            ->assertSet('endpoint', $endpoint);
    });

    test('adds https prefix when missing', function () {
        $endpoint = 'b52bf1144d4001acd18a39a8f258f90b.eu.r2.cloudflarestorage.com';

        Livewire::test(Create::class)
            ->call('updatedEndpoint', $endpoint)
            ->assertSet('endpoint', 'https://'.$endpoint);
    });

    test('preserves http prefix', function () {
        $endpoint = 'http://minio.local:9000';

        Livewire::test(Create::class)
            ->call('updatedEndpoint', $endpoint)
            ->assertSet('endpoint', $endpoint);
    });

    test('preserves DigitalOcean Spaces endpoint', function () {
        $endpoint = 'https://sfo3.digitaloceanspaces.com';

        Livewire::test(Create::class)
            ->call('updatedEndpoint', $endpoint)
            ->assertSet('endpoint', $endpoint);
    });

    test('preserves AWS S3 endpoint', function () {
        $endpoint = 'https://s3.eu-west-1.amazonaws.com';

        Livewire::test(Create::class)
            ->call('updatedEndpoint', $endpoint)
            ->assertSet('endpoint', $endpoint);
    });
});
