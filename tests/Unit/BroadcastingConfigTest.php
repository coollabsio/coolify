<?php

use Illuminate\Support\Env;

function broadcastingConfigWithEnvironment(array $overrides): array
{
    $keys = [
        'PUSHER_SCHEME',
        'PUSHER_BACKEND_SCHEME',
    ];
    $repository = Env::getRepository();
    $original = [];

    foreach ($keys as $key) {
        $original[$key] = $repository->get($key);
        $repository->clear($key);
    }

    try {
        foreach ($overrides as $key => $value) {
            $repository->set($key, $value);
        }

        return require __DIR__.'/../../config/broadcasting.php';
    } finally {
        foreach ($keys as $key) {
            $repository->clear($key);

            if ($original[$key] !== null) {
                $repository->set($key, $original[$key]);
            }
        }
    }
}

it('keeps the backend pusher connection on http when the browser scheme is https', function () {
    $options = broadcastingConfigWithEnvironment([
        'PUSHER_SCHEME' => 'https',
    ])['connections']['pusher']['options'];

    expect($options['scheme'])->toBe('http')
        ->and($options['useTLS'])->toBeFalse();
});

it('enables backend pusher TLS when its scheme is explicitly https', function () {
    $options = broadcastingConfigWithEnvironment([
        'PUSHER_SCHEME' => 'http',
        'PUSHER_BACKEND_SCHEME' => 'https',
    ])['connections']['pusher']['options'];

    expect($options['scheme'])->toBe('https')
        ->and($options['useTLS'])->toBeTrue();
});
