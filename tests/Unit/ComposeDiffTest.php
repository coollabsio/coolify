<?php

use App\Services\ComposeDiff;

$current = "services:\n  app:\n    image: nginx:1\n    restart: always\n";
$latest = "services:\n  app:\n    image: nginx:2\n    restart: always\n";

it('returns no hunks for identical compose', function () use ($current) {
    expect(ComposeDiff::hunks($current, $current))->toBe([]);
});

it('returns a hunk describing the changed line', function () use ($current, $latest) {
    $hunks = ComposeDiff::hunks($current, $latest);

    expect($hunks)->toHaveCount(1);
    $types = collect($hunks[0]['lines'])->pluck('type');
    expect($types)->toContain('remove');
    expect($types)->toContain('add');
});

it('applies an accepted hunk by taking the latest side', function () use ($current, $latest) {
    $result = ComposeDiff::apply($current, $latest, [0]);
    expect($result)->toContain('image: nginx:2');
    expect($result)->not->toContain('image: nginx:1');
});

it('keeps the current side when the hunk is rejected', function () use ($current, $latest) {
    $result = ComposeDiff::apply($current, $latest, []);
    expect($result)->toContain('image: nginx:1');
    expect($result)->not->toContain('image: nginx:2');
});

it('applies only the accepted hunk when there are several', function () {
    $current = "a: 1\nb: 2\nc: 3\n";
    $latest = "a: 9\nb: 2\nc: 8\n";
    $result = ComposeDiff::apply($current, $latest, [0]);

    expect($result)->toContain('a: 9');
    expect($result)->toContain('c: 3');
});

it('surrounds a change with both leading and trailing context', function () {
    $current = "l1\nl2\nl3\nl4\nOLD\nl6\nl7\nl8\n";
    $latest = "l1\nl2\nl3\nl4\nNEW\nl6\nl7\nl8\n";

    $hunks = ComposeDiff::hunks($current, $latest);
    expect($hunks)->toHaveCount(1);

    $lines = collect($hunks[0]['lines']);
    $texts = $lines->pluck('text')->all();

    // Up to CONTEXT (3) equal lines appear before the change, not only after it.
    expect($texts)->toContain('l2', 'l3', 'l4');
    $leadingContextIndex = array_search('l4', $texts, true);
    $removeIndex = $lines->search(fn ($line) => $line['type'] === 'remove');
    expect($leadingContextIndex)->toBeLessThan($removeIndex);
});

it('falls back to a whole-file replace hunk for very large composes', function () {
    $current = implode("\n", array_fill(0, 4100, 'a'))."\n";
    $latest = implode("\n", array_fill(0, 4100, 'b'))."\n";

    $hunks = ComposeDiff::hunks($current, $latest);
    expect($hunks)->toHaveCount(1);

    $types = collect($hunks[0]['lines'])->pluck('type')->unique()->values()->all();
    expect($types)->toContain('remove');
    expect($types)->toContain('add');
    expect($types)->not->toContain('context');

    $result = ComposeDiff::apply($current, $latest, [0]);
    expect($result)->toContain('b');
    expect($result)->not->toContain('a');
});

it('diffs two 2000-line composes that differ in one line as one small hunk', function () {
    $lines = array_map(fn (int $n): string => "line {$n}", range(1, 2000));
    $current = implode("\n", $lines)."\n";
    $lines[999] = 'changed';
    $latest = implode("\n", $lines)."\n";

    $hunks = ComposeDiff::hunks($current, $latest);

    expect($hunks)->toHaveCount(1)
        ->and(collect($hunks[0]['lines'])->where('type', 'remove')->pluck('text')->all())->toBe(['line 1000'])
        ->and(collect($hunks[0]['lines'])->where('type', 'add')->pluck('text')->all())->toBe(['changed'])
        ->and(ComposeDiff::apply($current, $latest, [0]))->toBe($latest);
});

it('falls back to one replace hunk for two fully different 2000-line composes', function () {
    $current = implode("\n", array_map(fn (int $n): string => "old {$n}", range(1, 2000)))."\n";
    $latest = implode("\n", array_map(fn (int $n): string => "new {$n}", range(1, 2000)))."\n";

    $hunks = ComposeDiff::hunks($current, $latest);

    expect($hunks)->toHaveCount(1)
        ->and(ComposeDiff::apply($current, $latest, [0]))->toBe($latest)
        ->and(ComposeDiff::apply($current, $latest, []))->toBe($current);
});
