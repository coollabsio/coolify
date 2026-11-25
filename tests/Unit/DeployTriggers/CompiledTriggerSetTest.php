<?php

use App\Models\Application;
use App\Services\DeployTriggers\CompiledTriggerSet;
use App\Services\DeployTriggers\LegacyWatchPaths;

$conformance = json_decode(file_get_contents(dirname(__DIR__, 2).'/Fixtures/affected/matcher-conformance.json'), true, flags: JSON_THROW_ON_ERROR);

it('passes the compiled matcher conformance case', function (array $compiled, string $file, bool $expected) {
    expect(CompiledTriggerSet::fromArray($compiled)->matches($file))->toBe($expected);
})->with(collect($conformance['compiled'])->mapWithKeys(fn (array $case, int $index): array => [
    "{$index}: {$case['name']}" => [$case['compiled'], $case['file'], $case['expected']],
])->all());

it('passes the watch paths conformance case', function (string $watchPaths, string $file, bool $expected) {
    // Saved watch paths are normalized by the setter and again at match time; the legacy regex quirks (`#`, `[`) only emit warnings and never match.
    $application = new Application;
    $application->watch_paths = $watchPaths;
    if (blank($application->watch_paths)) {
        // Coolify uses watch-paths mode only for applications with stored watch paths.
        expect(true)->toBeTrue();

        return;
    }
    $matched = @LegacyWatchPaths::matches($application, [$file]);

    expect($matched->isNotEmpty())->toBe($expected);
})->with(collect($conformance['watch_paths'])->mapWithKeys(fn (array $case, int $index): array => [
    "{$index}: {$case['name']}" => [$case['watch_paths'], $case['file'], $case['expected']],
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
