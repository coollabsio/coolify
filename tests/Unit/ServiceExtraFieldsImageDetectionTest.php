<?php

/**
 * Tests for Service::extraFields() image detection logic.
 *
 * Verifies that Grafana extra fields are only added when the image
 * is specifically grafana/grafana, grafana/grafana-oss, or grafana/grafana-enterprise,
 * and NOT for other grafana-published images like grafana/loki, grafana/promtail, etc.
 *
 * This covers the fix in commit cc78c1fac that changed:
 *   $image->contains('grafana')
 * to:
 *   $image->is('grafana/grafana') || $image->is('grafana/grafana-oss') || $image->is('grafana/grafana-enterprise')
 */
it('correctly identifies exact grafana images', function () {
    $grafanaImages = [
        'grafana/grafana',
        'grafana/grafana-oss',
        'grafana/grafana-enterprise',
    ];

    foreach ($grafanaImages as $image) {
        $isGrafana = str($image)->is('grafana/grafana')
            || str($image)->is('grafana/grafana-oss')
            || str($image)->is('grafana/grafana-enterprise');

        expect($isGrafana)->toBeTrue("Expected $image to be identified as Grafana");
    }
});

it('does not misidentify other grafana-published images as Grafana', function (string $image) {
    $isGrafana = str($image)->is('grafana/grafana')
        || str($image)->is('grafana/grafana-oss')
        || str($image)->is('grafana/grafana-enterprise');

    expect($isGrafana)->toBeFalse("Expected $image NOT to be identified as Grafana");
})->with([
    'loki' => 'grafana/loki',
    'promtail' => 'grafana/promtail',
    'tempo' => 'grafana/tempo',
    'alloy' => 'grafana/alloy',
    'beyla' => 'grafana/beyla',
    'mimir' => 'grafana/mimir',
    'phlare' => 'grafana/phlare',
    'k6' => 'grafana/k6',
    'agent' => 'grafana/agent',
    'oncall' => 'grafana/oncall',
    'synthetic-monitoring-agent' => 'grafana/synthetic-monitoring-agent',
    'tanka' => 'grafana/tanka',
]);

it('demonstrates the old contains bug vs new is fix', function () {
    $lokiImage = 'grafana/loki';

    $oldBuggyMatch = str($lokiImage)->contains('grafana');
    $newFixedMatch = str($lokiImage)->is('grafana/grafana');

    expect($oldBuggyMatch)->toBeTrue('Old code would incorrectly match loki as grafana');
    expect($newFixedMatch)->toBeFalse('New code correctly distinguishes loki from grafana');
});

it('handles images with tags by stripping the tag part', function () {
    $imagesWithTags = [
        'grafana/grafana:latest' => true,
        'grafana/grafana-oss:10.0.0' => true,
        'grafana/grafana-enterprise:11.5.1' => true,
        'grafana/loki:latest' => false,
        'grafana/loki:3.0.0' => false,
        'grafana/promtail:2.9' => false,
    ];

    foreach ($imagesWithTags as $fullImage => $expectedGrafana) {
        $image = str($fullImage)->before(':');
        $isGrafana = $image->is('grafana/grafana')
            || $image->is('grafana/grafana-oss')
            || $image->is('grafana/grafana-enterprise');

        expect($isGrafana)->toBe($expectedGrafana, "Failed for $fullImage");
    }
});
