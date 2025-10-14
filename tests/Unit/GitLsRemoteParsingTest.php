<?php

uses(\Tests\TestCase::class);

it('extracts commit SHA from git ls-remote output without warnings', function () {
    $output = "196d3df7665359a8c8fa3329a6bcde0267e550bf\trefs/heads/master";

    preg_match('/([0-9a-f]{40})\s*\t/', $output, $matches);
    $commit = $matches[1] ?? null;

    expect($commit)->toBe('196d3df7665359a8c8fa3329a6bcde0267e550bf');
});

it('extracts commit SHA from git ls-remote output with redirect warning on separate line', function () {
    $output = "warning: redirecting to https://tangled.org/@tangled.org/core/\n196d3df7665359a8c8fa3329a6bcde0267e550bf\trefs/heads/master";

    preg_match('/([0-9a-f]{40})\s*\t/', $output, $matches);
    $commit = $matches[1] ?? null;

    expect($commit)->toBe('196d3df7665359a8c8fa3329a6bcde0267e550bf');
});

it('extracts commit SHA from git ls-remote output with redirect warning on same line', function () {
    // This is the actual format from tangled.sh - warning and result on same line, no newline
    $output = "warning: redirecting to https://tangled.org/@tangled.org/core/196d3df7665359a8c8fa3329a6bcde0267e550bf\trefs/heads/master";

    preg_match('/([0-9a-f]{40})\s*\t/', $output, $matches);
    $commit = $matches[1] ?? null;

    expect($commit)->toBe('196d3df7665359a8c8fa3329a6bcde0267e550bf');
});

it('extracts commit SHA from git ls-remote output with multiple warning lines', function () {
    $output = "warning: redirecting to https://example.org/repo/\ninfo: some other message\n196d3df7665359a8c8fa3329a6bcde0267e550bf\trefs/heads/main";

    preg_match('/([0-9a-f]{40})\s*\t/', $output, $matches);
    $commit = $matches[1] ?? null;

    expect($commit)->toBe('196d3df7665359a8c8fa3329a6bcde0267e550bf');
});

it('handles git ls-remote output with extra whitespace', function () {
    $output = "  196d3df7665359a8c8fa3329a6bcde0267e550bf  \trefs/heads/master";

    preg_match('/([0-9a-f]{40})\s*\t/', $output, $matches);
    $commit = $matches[1] ?? null;

    expect($commit)->toBe('196d3df7665359a8c8fa3329a6bcde0267e550bf');
});
