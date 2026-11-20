<?php

use App\Ai\Agents\CoolifyAssistant;
use App\Ai\Tools\ReadDocPage;
use App\Ai\Tools\SearchDocs;
use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Once;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

function seedDocsCache(): void
{
    Http::fake(['coolify.io/docs/api/search' => Http::response([
        'index' => [
            'tokenOccurrences' => ['content' => ['deploy' => 1, 'application' => 1]],
            'frequencies' => ['content' => ['d1' => ['deploy' => 0.5, 'application' => 0.5]]],
            'fieldLengths' => ['content' => ['d1' => 2]],
            'avgFieldLength' => ['content' => 2],
        ],
        'docs' => ['docs' => [
            'd1' => ['pageId' => 'p1', 'title' => 'Deploying apps', 'url' => 'https://coolify.io/docs/deploy', 'content' => 'deploy application'],
        ]],
    ])]);
}

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_ai_assistant_enabled' => true]);
    Once::flush();
    Cache::flush();
});

test('search-docs returns ranked pages with their url', function () {
    seedDocsCache();

    $out = (string) app(SearchDocs::class)->handle(new Request(['query' => 'deploy']));

    expect($out)->toContain('Deploying apps')
        ->and($out)->toContain('https://coolify.io/docs/deploy')
        ->and($out)->toContain('p1');
});

test('read-doc-page returns the page content and handles unknown ids', function () {
    seedDocsCache();

    expect((string) app(ReadDocPage::class)->handle(new Request(['page_id' => 'p1'])))->toContain('deploy application')
        ->and((string) app(ReadDocPage::class)->handle(new Request(['page_id' => 'nope'])))->toContain('No documentation');
});

test('the tools degrade gracefully when docs are disabled', function () {
    InstanceSettings::query()->update(['is_ai_assistant_enabled' => false]);
    Once::flush();

    expect((string) app(SearchDocs::class)->handle(new Request(['query' => 'deploy'])))->toContain('unavailable');
});

test('the assistant exposes the docs tools only when the master switch is on', function () {
    $classes = array_map(fn ($t) => $t::class, (new CoolifyAssistant)->tools());
    expect($classes)->toContain(SearchDocs::class)->toContain(ReadDocPage::class);

    InstanceSettings::query()->update(['is_ai_assistant_enabled' => false]);
    Once::flush();

    $classesOff = array_map(fn ($t) => $t::class, (new CoolifyAssistant)->tools());
    expect($classesOff)->not->toContain(SearchDocs::class)->not->toContain(ReadDocPage::class);
});
