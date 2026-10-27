<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    $this->attempts = 0;
});

function fakeConnectionFailures(int $failures): void
{
    Http::fake(function () use ($failures) {
        test()->attempts++;

        return test()->attempts <= $failures ? Http::failedConnection() : Http::response(['ok' => true]);
    });
}

test('a read request is retried once after a connection failure', function () {
    fakeConnectionFailures(1);

    expect(Http::get('https://api.example.com/items')->json('ok'))->toBeTrue()
        ->and($this->attempts)->toBe(2);
});

test('a read request fails when the retry also has a connection failure', function () {
    fakeConnectionFailures(2);

    expect(fn () => Http::delete('https://api.example.com/items/1'))->toThrow(ConnectionException::class)
        ->and($this->attempts)->toBe(2);
});

test('a post request is not retried because the server can have received it', function () {
    fakeConnectionFailures(1);

    expect(fn () => Http::post('https://api.example.com/items'))->toThrow(ConnectionException::class)
        ->and($this->attempts)->toBe(1);
});
