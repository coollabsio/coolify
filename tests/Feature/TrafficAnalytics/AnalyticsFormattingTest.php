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

it('groups referer breakdown rows by normalized host', function () {
    expect(groupRefererBreakdownRows([
        ['value' => 'https://www.example.com/first', 'requests' => 7, 'bytesOut' => 700],
        ['value' => 'http://example.com/second', 'requests' => 3, 'bytesOut' => 300],
        ['value' => 'https://other.example/path', 'requests' => 5, 'bytesOut' => 500],
    ]))->toBe([
        ['value' => 'example.com', 'requests' => 10, 'bytesOut' => 1000],
        ['value' => 'other.example', 'requests' => 5, 'bytesOut' => 500],
    ]);
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

it('renders ampersands in breakdown empty-state descriptions', function () {
    $html = view('livewire.traffic._breakdown-section', [
        'dimension' => 'agent',
        'label' => 'AI agents & bots',
        'rows' => [],
    ])->render();

    expect($html)
        ->toContain('No ai agents &amp; bots data for the selected range.')
        ->not->toContain('No ai agents &amp;amp; bots data for the selected range.');
});

it('distinguishes a missing response status from numeric values in other dimensions', function (string $dimension, mixed $value, string $display) {
    $html = view('livewire.traffic._breakdown-section', [
        'dimension' => $dimension,
        'label' => 'Breakdown',
        'rows' => [['value' => $value, 'requests' => 2, 'bytesOut' => 0]],
    ])->render();

    expect($html)->toContain('title="'.$display.'"')
        ->toContain('title="2 requests"');
})->with([
    'integer zero status' => ['status', 0, 'No response status (0)'],
    'string zero status' => ['status', '0', 'No response status (0)'],
    'valid status' => ['status', '200', '200'],
    'missing status' => ['status', '', 'Unknown'],
    'overflow status' => ['status', '__other__', 'Other'],
    'zero in another dimension' => ['path', '0', '0'],
]);

it('detects container tooling user agents', function (?string $userAgent, bool $expected) {
    expect(isContainerToolingUserAgent($userAgent))->toBe($expected);
})->with([
    'buildkit' => ['buildkit/v0.33', true],
    'docker with compose upstream' => ['docker/29.6.2 go/go1.26.5 git-commit/3d80467 kernel/6.1.0-51-amd64 os/linux arch/amd64 UpstreamClient(compose/v5.3.1)', true],
    'docker-client' => ['docker-client/1.0', true],
    'containerd' => ['containerd/2.3.3+unknown', true],
    'podman' => ['podman/5.4.0', true],
    'skopeo' => ['skopeo/1.18.0', true],
    'cri-o' => ['cri-o/1.32.1', true],
    'mixed case' => ['Docker/29.6.2', true],
    'leading whitespace' => ['  buildkit/v0.33', true],
    'docker without slash' => ['docker', false],
    'docker as a later token' => ['curl/8.5.0 docker/29.6.2', false],
    'docker prefix of another name' => ['dockerd/29.6.2', false],
    'firefox' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:156.0) Gecko/20100101 Firefox/156.0', false],
    'curl' => ['curl/8.5.0', false],
    'overflow bucket' => ['__other__', false],
    'empty' => ['', false],
    'null' => [null, false],
]);
