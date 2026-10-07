<?php

use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

afterEach(function () {
    File::delete(storage_path('app/migration-failed.json'));
});

test('a failed migration fails the command and records the error', function () {
    app('migrator')->path(base_path('tests/Fixtures/Migrations'));

    $this->artisan('start:migration')->assertFailed();

    expect(File::json(storage_path('app/migration-failed.json')))
        ->toMatchArray(['error' => 'Intentionally broken migration (test fixture).'])
        ->toHaveKeys(['version', 'failed_at']);
});

test('a successful migration clears the recorded failure', function () {
    File::put(storage_path('app/migration-failed.json'), '{}');

    $this->artisan('start:migration')->assertSuccessful();

    expect(File::exists(storage_path('app/migration-failed.json')))->toBeFalse();
});

test('requests are answered with the error while a migration failure is recorded', function () {
    $this->withoutVite();
    InstanceSettings::forceCreate(['id' => 0]);
    File::put(storage_path('app/migration-failed.json'), json_encode([
        'version' => '4.0.0',
        'error' => 'relation "teams" does not exist',
        'failed_at' => now()->toIso8601String(),
    ]));

    $this->get('/login')
        ->assertStatus(503)
        ->assertSee('The database migration failed.')
        ->assertSee('relation "teams" does not exist');

    $this->getJson('/api/v1/servers')
        ->assertStatus(503)
        ->assertJson(['message' => 'Database migration failed: relation "teams" does not exist']);

    $this->get('/api/health')->assertOk();
});
