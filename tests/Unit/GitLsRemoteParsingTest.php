<?php

uses(\Tests\TestCase::class);

it('extracts commit SHA from git ls-remote output without warnings', function () {
    $output = "196d3df7665359a8c8fa3329a6bcde0267e550bf\trefs/heads/master";

    preg_match('/\b([0-9a-fA-F]{40})(?=\s*\t)/', $output, $matches);
    $commit = $matches[1] ?? null;

    expect($commit)->toBe('196d3df7665359a8c8fa3329a6bcde0267e550bf');
});

it('extracts commit SHA from git ls-remote output with redirect warning on separate line', function () {
    $output = "warning: redirecting to https://tangled.org/@tangled.org/core/\n196d3df7665359a8c8fa3329a6bcde0267e550bf\trefs/heads/master";

    preg_match('/\b([0-9a-fA-F]{40})(?=\s*\t)/', $output, $matches);
    $commit = $matches[1] ?? null;

    expect($commit)->toBe('196d3df7665359a8c8fa3329a6bcde0267e550bf');
});

it('extracts commit SHA from git ls-remote output with redirect warning on same line', function () {
    // This is the actual format from tangled.sh - warning and result on same line, no newline
    $output = "warning: redirecting to https://tangled.org/@tangled.org/core/196d3df7665359a8c8fa3329a6bcde0267e550bf\trefs/heads/master";

    preg_match('/\b([0-9a-fA-F]{40})(?=\s*\t)/', $output, $matches);
    $commit = $matches[1] ?? null;

    expect($commit)->toBe('196d3df7665359a8c8fa3329a6bcde0267e550bf');
});

it('extracts commit SHA from git ls-remote output with multiple warning lines', function () {
    $output = "warning: redirecting to https://example.org/repo/\ninfo: some other message\n196d3df7665359a8c8fa3329a6bcde0267e550bf\trefs/heads/main";

    preg_match('/\b([0-9a-fA-F]{40})(?=\s*\t)/', $output, $matches);
    $commit = $matches[1] ?? null;

    expect($commit)->toBe('196d3df7665359a8c8fa3329a6bcde0267e550bf');
});

it('handles git ls-remote output with extra whitespace', function () {
    $output = "  196d3df7665359a8c8fa3329a6bcde0267e550bf  \trefs/heads/master";

    preg_match('/\b([0-9a-fA-F]{40})(?=\s*\t)/', $output, $matches);
    $commit = $matches[1] ?? null;

    expect($commit)->toBe('196d3df7665359a8c8fa3329a6bcde0267e550bf');
});

it('extracts commit SHA with uppercase letters and normalizes to lowercase', function () {
    $output = "196D3DF7665359A8C8FA3329A6BCDE0267E550BF\trefs/heads/master";

    preg_match('/\b([0-9a-fA-F]{40})(?=\s*\t)/', $output, $matches);
    $commit = $matches[1] ?? null;

    // Git SHAs are case-insensitive, so we normalize to lowercase for comparison
    expect(strtolower($commit))->toBe('196d3df7665359a8c8fa3329a6bcde0267e550bf');
});

it('returns null when no commit SHA is present in output', function () {
    $output = "warning: redirecting to https://example.org/repo/\nError: repository not found";

    preg_match('/\b([0-9a-fA-F]{40})(?=\s*\t)/', $output, $matches);
    $commit = $matches[1] ?? null;

    expect($commit)->toBeNull();
});

it('returns null when output has tab but no valid SHA', function () {
    $output = "invalid-sha-format\trefs/heads/master";

    preg_match('/\b([0-9a-fA-F]{40})(?=\s*\t)/', $output, $matches);
    $commit = $matches[1] ?? null;

    expect($commit)->toBeNull();
});
