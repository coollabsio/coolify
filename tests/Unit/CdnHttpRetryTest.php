<?php

use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Sleep::fake();
    $this->sleptSeconds = [];
    Sleep::whenFakingSleep(fn (CarbonInterval $duration) => $this->sleptSeconds[] = $duration->totalSeconds);
});

it('waits for the Retry-After of a Cloudflare 1200 response before it retries', function () {
    Http::fakeSequence()
        ->push('error code: 1200', 503, ['Retry-After' => '60'])
        ->push(['coolify' => ['helper' => ['version' => '1.0.0']]], 200);

    $response = cdn_http()->get('https://cdn.example.com/coolify/versions.json');

    expect($response->successful())->toBeTrue();
    Http::assertSentCount(2);
    expect($this->sleptSeconds)->toHaveCount(1)
        ->and($this->sleptSeconds[0])->toBeGreaterThanOrEqual(60)->toBeLessThanOrEqual(120);
});

it('backs off exponentially when the response has no Retry-After', function () {
    Http::fakeSequence()
        ->push('', 502)
        ->push('', 502)
        ->push('', 502);

    $response = cdn_http()->get('https://cdn.example.com/coolify/versions.json');

    expect($response->status())->toBe(502);
    Http::assertSentCount(3);
    expect($this->sleptSeconds)->toHaveCount(2)
        ->and($this->sleptSeconds[0])->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(4)
        ->and($this->sleptSeconds[1])->toBeGreaterThanOrEqual(4)->toBeLessThanOrEqual(8);
});

it('does not retry a client error', function () {
    Http::fakeSequence()->push('', 404);

    $response = cdn_http()->get('https://cdn.example.com/coolify/versions.json');

    expect($response->status())->toBe(404);
    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});
