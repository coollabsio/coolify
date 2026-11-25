<?php

use App\Services\DeployTriggers\CompiledTriggerSet;

$conformance = json_decode(file_get_contents(dirname(__DIR__, 2).'/Fixtures/affected/matcher-conformance.json'), true, flags: JSON_THROW_ON_ERROR);

it('passes the matcher conformance case', function (array $compiled, string $file, bool $expected) {
    expect(CompiledTriggerSet::fromArray($compiled)->matches($file))->toBe($expected);
})->with(collect($conformance['cases'])->mapWithKeys(fn (array $case): array => [
    $case['note'] => [$case['compiled'], $case['file'], $case['expected']],
])->all());

it('rejects unknown rule kinds', function () {
    CompiledTriggerSet::fromArray(['version' => 1, 'all_of' => [[
        'limit' => 'workspace', 'default' => 'include',
        'rules' => [['action' => 'include', 'kind' => 'regex', 'pattern' => '.*']],
    ]], 'lockfiles' => []]);
})->throws(InvalidArgumentException::class);

it('rejects an unknown version', function () {
    CompiledTriggerSet::fromArray(['version' => 2, 'all_of' => [], 'lockfiles' => []]);
})->throws(InvalidArgumentException::class);

it('returns the first matching path and knows the lockfiles', function () {
    $set = CompiledTriggerSet::fromArray(['version' => 1, 'all_of' => [[
        'limit' => 'workspace', 'default' => 'exclude',
        'rules' => [['action' => 'include', 'kind' => 'dir', 'path' => 'apps/web']],
    ]], 'lockfiles' => ['pnpm-lock.yaml']]);

    expect($set->firstMatch(['README.md', 'apps/web/a.ts', 'apps/web/b.ts']))->toBe('apps/web/a.ts')
        ->and($set->firstMatch(['README.md']))->toBeNull()
        ->and($set->lockfiles())->toBe(['pnpm-lock.yaml'])
        ->and($set->isLockfile('pnpm-lock.yaml'))->toBeTrue()
        ->and($set->isLockfile('apps/web/pnpm-lock.yaml'))->toBeFalse();
});

it('matches 50k files against 500 dir rules under 500ms', function () {
    $rules = [];
    for ($i = 0; $i < 500; $i++) {
        $rules[] = ['action' => $i % 2 === 0 ? 'exclude' : 'include', 'kind' => 'dir', 'path' => "packages/pkg-{$i}"];
    }
    $rules[] = ['action' => 'include', 'kind' => 'glob', 'pattern' => '**/package.json'];
    $set = CompiledTriggerSet::fromArray(['version' => 1, 'all_of' => [['limit' => 'workspace', 'default' => 'include', 'rules' => $rules]], 'lockfiles' => []]);
    $files = [];
    for ($i = 0; $i < 50000; $i++) {
        $files[] = 'packages/pkg-'.($i % 600).'/src/deep/file-'.$i.'.ts';
    }

    $start = hrtime(true);
    foreach ($files as $file) {
        $set->matches($file);
    }
    $elapsedMs = (hrtime(true) - $start) / 1e6;

    expect($elapsedMs)->toBeLessThan(500);
});
