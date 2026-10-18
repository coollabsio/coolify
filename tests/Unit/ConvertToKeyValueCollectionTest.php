<?php

it('keeps bare list-style build args as named keys when merged into a map environment', function () {
    $environment = collect(['SENTRY_CONF' => '/etc/sentry', 'SENTRY_IMAGE' => 'getsentry/sentry'])
        ->merge(['SENTRY_IMAGE', 'DOCKER_PLATFORM', 'FOO=bar']);

    expect(convertToKeyValueCollection($environment)->all())->toBe([
        'SENTRY_CONF' => '/etc/sentry',
        'SENTRY_IMAGE' => 'getsentry/sentry',
        'DOCKER_PLATFORM' => null,
        'FOO' => 'bar',
    ]);
});
