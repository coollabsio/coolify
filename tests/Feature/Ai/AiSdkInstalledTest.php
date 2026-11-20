<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Enums\Lab;

use function Laravel\Ai\agent;

test('the Lab enum and agent() helper are available', function () {
    expect(enum_exists(Lab::class))->toBeTrue()
        ->and(function_exists('Laravel\\Ai\\agent'))->toBeTrue();
});

test('a runtime-registered openai-compatible provider is used for generation', function () {
    config(['ai.providers.byok-test' => [
        'driver' => 'openai-compatible',
        'url' => 'http://provider.test/v1',
        'key' => 'byok-secret',
        'models' => ['text' => ['default' => 'test-model']],
    ]]);

    Http::fake(['provider.test/*' => Http::response([
        'id' => 'x',
        'object' => 'chat.completion',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'OK'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
    ])]);

    $response = agent('Reply concisely.')->prompt('ping', provider: 'byok-test', model: 'test-model');

    expect($response->text)->toBe('OK');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'provider.test/v1/chat/completions')
        && $request->hasHeader('Authorization', 'Bearer byok-secret'));
});
