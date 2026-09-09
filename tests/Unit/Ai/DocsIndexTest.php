<?php

use App\Ai\Docs\DocsIndex;

function normalizedFixture(): array
{
    return [
        'n' => 2,
        'avgFieldLength' => 3.5,
        'tokenOccurrences' => [
            'deploy' => 1, 'application' => 1, 'deployment' => 1, 'queue' => 1,
            'database' => 1, 'backup' => 1, 'postgres' => 1,
        ],
        'frequencies' => [
            'd1' => ['deploy' => 0.25, 'application' => 0.25, 'deployment' => 0.25, 'queue' => 0.25],
            'd2' => ['database' => 1 / 3, 'backup' => 1 / 3, 'postgres' => 1 / 3],
        ],
        'fieldLengths' => ['d1' => 4, 'd2' => 3],
        'docs' => [
            'd1' => ['pageId' => 'p1', 'title' => 'Deployments', 'url' => 'https://coolify.io/docs/deploy', 'content' => 'deploy application deployment queue'],
            'd2' => ['pageId' => 'p2', 'title' => 'Databases', 'url' => 'https://coolify.io/docs/db', 'content' => 'database backup postgres'],
        ],
    ];
}

test('a well-formed index is valid', function () {
    expect((new DocsIndex(normalizedFixture()))->isValid())->toBeTrue();
});

test('an index missing core maps is invalid', function () {
    $bad = normalizedFixture();
    unset($bad['tokenOccurrences']);

    expect((new DocsIndex($bad))->isValid())->toBeFalse();
});

test('search ranks the page whose chunks match the query first', function () {
    $index = new DocsIndex(normalizedFixture());

    $deploy = $index->search('deploy', 5);
    expect($deploy[0]['page_id'])->toBe('p1')
        ->and($deploy[0]['url'])->toBe('https://coolify.io/docs/deploy');

    $db = $index->search('database backup', 5);
    expect($db[0]['page_id'])->toBe('p2');
});

test('search respects the limit and returns a snippet', function () {
    $index = new DocsIndex(normalizedFixture());
    $results = $index->search('deploy database', 1);

    expect($results)->toHaveCount(1)
        ->and($results[0])->toHaveKeys(['page_id', 'title', 'url', 'snippet', 'score']);
});

test('page concatenates a page chunks and rejects unknown ids', function () {
    $index = new DocsIndex(normalizedFixture());

    expect($index->page('p1'))->toContain('deploy application')
        ->and($index->page('nope'))->toBe('');
});
