<?php

test('format_docker_labels_to_json preserves equals in values', function () {
    $rawLabels = 'coolify.config=foo=bar,com.docker.compose.project=coolify';

    $labels = format_docker_labels_to_json($rawLabels);

    expect($labels->get('coolify.config'))->toBe('foo=bar')
        ->and($labels->get('com.docker.compose.project'))->toBe('coolify');
});

test('format_docker_labels_to_json returns empty collection for empty input', function () {
    $labels = format_docker_labels_to_json('');

    expect($labels->isEmpty())->toBeTrue();
});
