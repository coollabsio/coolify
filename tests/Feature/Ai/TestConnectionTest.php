<?php

use App\Ai\TestConnection;
use App\Enums\AiProvider;
use App\Models\AiProviderCredential;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('a working credential returns ok', function () {
    $cred = AiProviderCredential::factory()->for(Team::factory())->create([
        'provider' => AiProvider::OPENAI_COMPATIBLE,
        'model' => 'local-model',
        'api_key' => 'k-good',
        'base_url' => 'http://prov.test/v1',
    ]);

    Http::fake(['prov.test/*' => Http::response([
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'OK'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
    ])]);

    expect(app(TestConnection::class)->handle($cred))
        ->toMatchArray(['ok' => true]);
});

test('a failing provider returns ok=false with a message', function () {
    $cred = AiProviderCredential::factory()->for(Team::factory())->create([
        'provider' => AiProvider::OPENAI_COMPATIBLE,
        'model' => 'local-model',
        'api_key' => 'k-bad',
        'base_url' => 'http://prov.test/v1',
    ]);

    Http::fake(['prov.test/*' => Http::response(['error' => ['message' => 'invalid key']], 401)]);

    $result = app(TestConnection::class)->handle($cred);
    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->toBeString()->not->toBeEmpty();
});
