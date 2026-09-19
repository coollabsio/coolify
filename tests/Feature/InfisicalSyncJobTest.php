<?php

use App\Jobs\InfisicalSyncJob;
use App\Models\InfisicalBinding;
use App\Models\SharedEnvironmentVariable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

test('the job syncs a binding', function () {
    Http::fake([
        '*/api/v1/auth/universal-auth/login' => Http::response(['accessToken' => 'token-123']),
        '*/api/v3/secrets/raw*' => Http::response([
            'secrets' => [['secretKey' => 'DB_PASSWORD', 'secretValue' => 'hunter2']],
        ]),
    ]);

    $binding = InfisicalBinding::factory()->create();

    (new InfisicalSyncJob($binding))->handle();

    expect(SharedEnvironmentVariable::where('key', 'DB_PASSWORD')->exists())->toBeTrue();
});

test('the job logs and swallows an infisical failure', function () {
    Http::fake(['*' => Http::response(['message' => 'down'], 500)]);
    Log::spy();

    $binding = InfisicalBinding::factory()->create();

    (new InfisicalSyncJob($binding))->handle();

    Log::shouldHaveReceived('warning')->once();
    $binding->refresh();
    expect($binding->last_sync_status)->toBe(InfisicalBinding::STATUS_FAILED);
});
