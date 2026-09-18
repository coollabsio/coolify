<?php

use App\Ai\Docs\DocsIndex;
use App\Ai\Docs\DocsIndexStore;
use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

function rawOramaFixture(): array
{
    return [
        'index' => [
            'tokenOccurrences' => ['content' => ['deploy' => 1, 'application' => 1, 'database' => 1, 'backup' => 1]],
            'frequencies' => ['content' => [
                'd1' => ['deploy' => 0.5, 'application' => 0.5],
                'd2' => ['database' => 0.5, 'backup' => 0.5],
            ]],
            'fieldLengths' => ['content' => ['d1' => 2, 'd2' => 2]],
            'avgFieldLength' => ['content' => 2],
        ],
        'docs' => [
            'docs' => [
                'd1' => ['pageId' => 'p1', 'title' => 'Deploy', 'url' => 'https://coolify.io/docs/deploy', 'content' => 'deploy application'],
                'd2' => ['pageId' => 'p2', 'title' => 'Backups', 'url' => 'https://coolify.io/docs/backup', 'content' => 'database backup'],
            ],
        ],
    ];
}

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_ai_assistant_enabled' => true]);
    Once::flush();
    Cache::flush();
});

test('master switch off means no fetch and no cache population', function () {
    InstanceSettings::query()->update(['is_ai_assistant_enabled' => false]);
    Once::flush();
    Http::fake();

    expect(app(DocsIndexStore::class)->get())->toBeNull();
    Http::assertNothingSent();
    expect(Cache::has('ai:docs:index'))->toBeFalse();
});

test('first use lazily fetches, caches forever, and returns a searchable index', function () {
    Http::fake(['coolify.io/docs/api/search' => Http::response(rawOramaFixture())]);

    $index = app(DocsIndexStore::class)->get();

    expect($index)->toBeInstanceOf(DocsIndex::class)
        ->and($index->search('deploy', 5)[0]['page_id'])->toBe('p1')
        ->and(Cache::has('ai:docs:index'))->toBeTrue()
        ->and(Cache::has('ai:docs:hash'))->toBeTrue();
    Http::assertSentCount(1);
});

test('refresh overwrites only when the content hash changes', function () {
    $changed = rawOramaFixture();
    $changed['docs']['docs']['d3'] = ['pageId' => 'p3', 'title' => 'New', 'url' => 'https://coolify.io/docs/new', 'content' => 'ingress routing'];
    $changed['index']['tokenOccurrences']['content']['ingress'] = 1;
    $changed['index']['frequencies']['content']['d3'] = ['ingress' => 0.5, 'routing' => 0.5];
    $changed['index']['fieldLengths']['content']['d3'] = 2;

    Http::fakeSequence('coolify.io/docs/api/search')
        ->push(rawOramaFixture())
        ->push(rawOramaFixture())
        ->push($changed);

    $store = app(DocsIndexStore::class);

    $store->refresh();
    $firstHash = Cache::get('ai:docs:hash');

    $store->refresh(); // identical content: no rewrite
    expect(Cache::get('ai:docs:hash'))->toBe($firstHash);

    $store->refresh(); // changed content: hash updates
    expect(Cache::get('ai:docs:hash'))->not->toBe($firstHash);
});

test('a malformed payload does not clobber a good cached index', function () {
    Http::fake(['coolify.io/docs/api/search' => Http::response(rawOramaFixture())]);
    $store = app(DocsIndexStore::class);
    $store->refresh();
    $goodHash = Cache::get('ai:docs:hash');

    Http::fake(['coolify.io/docs/api/search' => Http::response(['garbage' => true])]);
    $store->refresh();

    expect(Cache::get('ai:docs:hash'))->toBe($goodHash)
        ->and(app(DocsIndexStore::class)->get())->toBeInstanceOf(DocsIndex::class);
});
