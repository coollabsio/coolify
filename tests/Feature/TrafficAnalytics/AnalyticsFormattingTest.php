<?php

// Presentation helpers for the analytics views (items: flags, referrer favicons,
// device capitalization).

it('maps woothee device categories to friendly capitalized labels', function () {
    expect(deviceLabel('pc'))->toBe('Desktop');
    expect(deviceLabel('smartphone'))->toBe('Mobile');
    expect(deviceLabel('mobilephone'))->toBe('Mobile');
    expect(deviceLabel('crawler'))->toBe('Bot');
    expect(deviceLabel('tablet'))->toBe('Tablet');
    expect(deviceLabel(''))->toBe('Unknown');
    expect(deviceLabel(null))->toBe('Unknown');
});

it('extracts a bare host from referer URLs and bare hosts, dropping www', function () {
    expect(refererHost('https://www.google.com/search?q=x'))->toBe('google.com');
    expect(refererHost('http://news.ycombinator.com'))->toBe('news.ycombinator.com');
    expect(refererHost('example.com'))->toBe('example.com');
    expect(refererHost(''))->toBeNull();
    expect(refererHost(null))->toBeNull();
});

it('builds a duckduckgo favicon url for a host', function () {
    expect(refererFaviconUrl('example.com'))->toBe('https://icons.duckduckgo.com/ip3/example.com.ico');
});

it('builds a flagcdn image url for valid ISO codes only', function () {
    expect(countryFlagUrl('US'))->toBe('https://flagcdn.com/24x18/us.png');
    expect(countryFlagUrl('de', '48x36'))->toBe('https://flagcdn.com/48x36/de.png');
    expect(countryFlagUrl('ZZZ'))->toBeNull();
    expect(countryFlagUrl(''))->toBeNull();
    expect(countryFlagUrl(null))->toBeNull();
});
