<?php

use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake([
        'discord.com/*' => Http::response([], 204),
    ]);
});

it('rejects feedback with missing content', function () {
    $response = $this->postJson('/api/feedback', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('content');
});

it('rejects feedback with content too short', function () {
    $response = $this->postJson('/api/feedback', ['content' => 'short']);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('content');
});

it('rejects feedback with content too long', function () {
    $response = $this->postJson('/api/feedback', ['content' => str_repeat('a', 2001)]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('content');
});

it('rejects feedback with non-string content', function () {
    $response = $this->postJson('/api/feedback', ['content' => ['array', 'value']]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('content');
});

it('accepts valid feedback and forwards to discord with mentions disabled', function () {
    config()->set('constants.webhooks.feedback_discord_webhook', 'https://discord.com/api/webhooks/test');

    $response = $this->postJson('/api/feedback', [
        'content' => 'This is a valid feedback message for testing purposes.',
    ]);

    $response->assertStatus(200)
        ->assertJson(['message' => 'Feedback sent.']);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://discord.com/api/webhooks/test'
            && $request['content'] === 'This is a valid feedback message for testing purposes.'
            && $request['allowed_mentions'] === ['parse' => []];
    });
});

it('does not forward to discord when webhook url is not configured', function () {
    config()->set('constants.webhooks.feedback_discord_webhook', null);

    $response = $this->postJson('/api/feedback', [
        'content' => 'This is a valid feedback message for testing purposes.',
    ]);

    $response->assertStatus(200);

    Http::assertNothingSent();
});

it('throttles feedback endpoint after 3 requests per minute', function () {
    config()->set('constants.webhooks.feedback_discord_webhook', null);

    for ($i = 0; $i < 3; $i++) {
        $response = $this->postJson('/api/feedback', [
            'content' => "Valid feedback message number {$i} for testing.",
        ]);
        $response->assertStatus(200);
    }

    $response = $this->postJson('/api/feedback', [
        'content' => 'This fourth request should be throttled.',
    ]);
    $response->assertStatus(429);
});

it('disables discord mention parsing regardless of content', function () {
    config()->set('constants.webhooks.feedback_discord_webhook', 'https://discord.com/api/webhooks/test');

    $response = $this->postJson('/api/feedback', [
        'content' => 'User feedback includes an @everyone style phrase and a link https://example.com for reference.',
    ]);

    $response->assertStatus(200);

    Http::assertSent(function ($request) {
        return $request['allowed_mentions'] === ['parse' => []];
    });
});
