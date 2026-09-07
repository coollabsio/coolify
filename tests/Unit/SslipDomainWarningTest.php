<?php

/**
 * Regression for #11079 / #11030: clearing domains normalizes to null, then
 * sslipDomainWarning must accept null without a TypeError.
 */
it('returns false when domains are null', function () {
    expect(sslipDomainWarning(null))->toBeFalse();
});

it('returns false when domains are empty or whitespace', function () {
    expect(sslipDomainWarning(''))->toBeFalse();
    expect(sslipDomainWarning('   '))->toBeFalse();
});

it('returns false for non-sslip https domains', function () {
    expect(sslipDomainWarning('https://example.com'))->toBeFalse();
    expect(sslipDomainWarning('http://app.example.com,https://www.example.com'))->toBeFalse();
});

it('returns false for http sslip domains without https', function () {
    expect(sslipDomainWarning('http://app.127.0.0.1.sslip.io'))->toBeFalse();
});

it('returns true when any domain uses https with sslip', function () {
    expect(sslipDomainWarning('https://app.127.0.0.1.sslip.io'))->toBeTrue();
    expect(sslipDomainWarning('https://example.com,https://app.127.0.0.1.sslip.io'))->toBeTrue();
});
