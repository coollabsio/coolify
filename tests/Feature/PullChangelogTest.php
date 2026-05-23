<?php

use App\Jobs\PullChangelog;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Fake releases land in a month that no real release uses, so the generated
 * changelog file never collides with committed changelogs.
 */
function fakeReleasesPayload(): array
{
    return [
        [
            'tag_name' => 'v9.9.9',
            'name' => 'Test Release',
            'body' => 'Released notes here.',
            'draft' => false,
            'published_at' => '1999-01-15T00:00:00Z',
        ],
        [
            'tag_name' => 'v9.9.8-draft',
            'name' => 'Draft Release',
            'body' => 'Should be skipped.',
            'draft' => true,
            'published_at' => '1999-01-10T00:00:00Z',
        ],
    ];
}

afterEach(function () {
    File::delete(base_path('changelogs/1999-01.json'));
});

test('releases_url config defaults to the GitHub raw source', function () {
    expect(config('constants.coolify.releases_url'))
        ->toBe('https://raw.githubusercontent.com/coollabsio/coolify-cdn/main/json/releases.json');
});

test('PullChangelog fetches from the configured releases_url and writes the changelog', function () {
    config(['constants.coolify.releases_url' => 'https://example.test/releases.json']);

    Http::fake([
        'https://example.test/releases.json' => Http::response(fakeReleasesPayload(), 200),
    ]);

    (new PullChangelog)->handle();

    Http::assertSent(fn ($request) => $request->url() === 'https://example.test/releases.json');

    $path = base_path('changelogs/1999-01.json');
    expect(File::exists($path))->toBeTrue();

    $data = json_decode(File::get($path), true);
    expect($data['entries'])->toHaveCount(1)
        ->and($data['entries'][0]['tag_name'])->toBe('v9.9.9');
});

test('PullChangelog skips draft releases', function () {
    config(['constants.coolify.releases_url' => 'https://example.test/releases.json']);

    Http::fake([
        'https://example.test/releases.json' => Http::response(fakeReleasesPayload(), 200),
    ]);

    (new PullChangelog)->handle();

    $data = json_decode(File::get(base_path('changelogs/1999-01.json')), true);

    $tags = array_column($data['entries'], 'tag_name');
    expect($tags)->not->toContain('v9.9.8-draft');
});
