<?php

it('decodes nested base64 container labels to label text', function () {
    $labels = "traefik.enable=true\ntraefik.http.routers.web.rule=Host(`example.com`)";

    expect(decodeBase64EncodedLabels(base64_encode(base64_encode($labels))))->toBe($labels);
});

it('decodes single encoded container labels without changing them', function () {
    $labels = "traefik.enable=true\ncom.example.version=1";

    expect(decodeBase64EncodedLabels(base64_encode($labels)))->toBe($labels);
});

it('rejects values that are not valid base64', function () {
    expect(decodeBase64EncodedLabels('traefik.enable=true'))->toBeNull();
});

it('handles an empty encoded value', function () {
    expect(decodeBase64EncodedLabels(''))->toBe('');
});

it('does not decode label text that happens to be valid base64', function () {
    expect(decodeBase64EncodedLabels(base64_encode('foo=')))->toBe('foo=');
});
