<?php

use App\Services\DeployTriggers\Resolution\DockerignoreRules;

it('turns ignore patterns into exclude rules under the context', function () {
    $rules = DockerignoreRules::parse("# comment\nnode_modules\n/dist\n*.md\n**/*.test.ts\n\n.git", 'apps/web');

    expect($rules)->toBe([
        ['action' => 'exclude', 'kind' => 'dir', 'path' => 'apps/web/node_modules'],
        ['action' => 'exclude', 'kind' => 'dir', 'path' => 'apps/web/dist'],
        ['action' => 'exclude', 'kind' => 'glob', 'pattern' => 'apps/web/*.md'],
        ['action' => 'exclude', 'kind' => 'glob', 'pattern' => 'apps/web/*.md/**'],
        ['action' => 'exclude', 'kind' => 'glob', 'pattern' => 'apps/web/**/*.test.ts'],
        ['action' => 'exclude', 'kind' => 'glob', 'pattern' => 'apps/web/**/*.test.ts/**'],
        ['action' => 'exclude', 'kind' => 'dir', 'path' => 'apps/web/.git'],
    ]);
});

it('uses the repository root for a root context', function () {
    expect(DockerignoreRules::parse('tmp', ''))->toBe([['action' => 'exclude', 'kind' => 'dir', 'path' => 'tmp']]);
});

it('ignores the whole file when it has exceptions or classes', function (string $content) {
    expect(DockerignoreRules::parse($content, ''))->toBe([]);
})->with(["*\n!src", 'file[0-9].txt', '../outside']);
