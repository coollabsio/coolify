<?php

use App\Services\TemplateFingerprint;

it('produces identical hashes for compose differing only in key order and whitespace', function () {
    $a = "services:\n  db:\n    image: postgres:15\n    restart: always\n";
    $b = "services:\n  db:\n    restart: always\n    image: postgres:15\n\n";

    expect(TemplateFingerprint::hash($a))->toBe(TemplateFingerprint::hash($b));
});

it('produces different hashes when a value changes', function () {
    $a = "services:\n  db:\n    image: postgres:15\n";
    $b = "services:\n  db:\n    image: postgres:16\n";

    expect(TemplateFingerprint::hash($a))->not->toBe(TemplateFingerprint::hash($b));
});

it('falls back to a string hash for unparseable yaml without throwing', function () {
    $broken = "services:\n  db:\n   image: [unclosed\n";

    expect(TemplateFingerprint::hash($broken))->toHaveLength(64);
});

it('hashes a template entry from its base64 compose', function () {
    $compose = "services:\n  app:\n    image: nginx\n";
    $template = ['compose' => base64_encode($compose)];

    expect(TemplateFingerprint::forTemplate($template))->toBe(TemplateFingerprint::hash($compose));
});

it('returns null for a template entry without a compose key', function () {
    expect(TemplateFingerprint::forTemplate(['envs' => 'x']))->toBeNull();
});

it('returns null for a template whose compose is not valid base64', function () {
    expect(TemplateFingerprint::forTemplate(['compose' => 'not*valid*base64!!']))->toBeNull();
});
