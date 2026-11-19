<?php

it('returns zero for null or empty fqdn', function () {
    expect(countDomains(null))->toBe(0)
        ->and(countDomains(''))->toBe(0)
        ->and(countDomains('   '))->toBe(0);
});

it('counts a single domain', function () {
    expect(countDomains('https://app.coolify.io'))->toBe(1)
        ->and(countDomains(' https://app.coolify.io '))->toBe(1);
});

it('counts multiple comma-separated domains', function () {
    expect(countDomains('https://app.coolify.io,https://cloud.coolify.io/dashboard'))->toBe(2)
        ->and(countDomains('https://a.com, https://b.com , https://c.com'))->toBe(3);
});

it('ignores empty segments between commas', function () {
    expect(countDomains('https://a.com,,https://b.com,'))->toBe(2)
        ->and(countDomains(',,,'))->toBe(0);
});
