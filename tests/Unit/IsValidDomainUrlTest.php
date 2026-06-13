<?php

it('accepts hostnames containing underscores', function () {
    // Regression: PHP's FILTER_VALIDATE_URL rejects underscores in the host,
    // which blocked valid service domains (e.g. Docker service naming) from
    // being saved and getting Let's Encrypt certificates. See issue #10597.
    expect(isValidDomainUrl('https://myapp_service.example.com'))->toBeTrue();
    expect(isValidDomainUrl('http://my_app.example.com'))->toBeTrue();
    expect(isValidDomainUrl('https://a_b_c.example.com/path'))->toBeTrue();
});

it('accepts ordinary domains and URLs', function () {
    expect(isValidDomainUrl('https://example.com'))->toBeTrue();
    expect(isValidDomainUrl('http://sub.example.com:8080/path?q=1'))->toBeTrue();
    expect(isValidDomainUrl('https://example.com/a_b'))->toBeTrue();
});

it('rejects strings that are not valid URLs', function () {
    expect(isValidDomainUrl('not a url'))->toBeFalse();
    expect(isValidDomainUrl('example.com'))->toBeFalse();
    expect(isValidDomainUrl(''))->toBeFalse();
});
