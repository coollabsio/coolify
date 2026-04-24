<?php

test('non-wildcard hosts produce byte-identical Host() rules', function () {
    expect(traefikHostRule('example.com'))->toBe('Host(`example.com`)');
    expect(traefikHostRule('app.example.com'))->toBe('Host(`app.example.com`)');
    expect(traefikHostRule('a-b-c.example.com'))->toBe('Host(`a-b-c.example.com`)');
    expect(traefikHostRule('www.example.com'))->toBe('Host(`www.example.com`)');
    expect(traefikHostRule('deeply.nested.sub.example.com'))->toBe('Host(`deeply.nested.sub.example.com`)');
});

test('wildcard at apex is converted to anchored HostRegexp', function () {
    expect(traefikHostRule('*.example.com'))
        ->toBe('HostRegexp(`^[a-z0-9-]+\.example\.com$`)');
});

test('wildcard with deeper parent domain is converted to anchored HostRegexp', function () {
    expect(traefikHostRule('*.sandbox.example.com'))
        ->toBe('HostRegexp(`^[a-z0-9-]+\.sandbox\.example\.com$`)');
});

test('emitted regex is anchored on both ends to prevent host-header smuggling', function () {
    $rule = traefikHostRule('*.example.com');
    expect($rule)->toContain('`^[');
    expect($rule)->toContain('com$`');
});

test('multi-wildcard hosts fall through to Host() unchanged', function () {
    expect(traefikHostRule('*.*.example.com'))->toBe('Host(`*.*.example.com`)');
});

test('wildcard not at leftmost position falls through to Host() unchanged', function () {
    expect(traefikHostRule('foo.*.example.com'))->toBe('Host(`foo.*.example.com`)');
});

test('bare wildcard and malformed wildcard inputs fall through to Host() unchanged', function () {
    expect(traefikHostRule('*'))->toBe('Host(`*`)');
    expect(traefikHostRule('*.'))->toBe('Host(`*.`)');
    expect(traefikHostRule('*example.com'))->toBe('Host(`*example.com`)');
});

test('trailing dot in wildcard host falls through to Host() unchanged', function () {
    expect(traefikHostRule('*.example.com.'))->toBe('Host(`*.example.com.`)');
});

test('hyphenated DNS labels are preserved through the transform', function () {
    expect(traefikHostRule('*.my-app.example.com'))
        ->toBe('HostRegexp(`^[a-z0-9-]+\.my-app\.example\.com$`)');
});
