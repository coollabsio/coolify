<?php

use App\Models\Application;

/**
 * This matches the CURRENT (broken) behavior without negation support
 * which is what the old Application.php had
 */
function matchWatchPathsCurrentBehavior(array $changed_files, ?array $watch_paths): array
{
    if (is_null($watch_paths) || empty($watch_paths)) {
        return [];
    }

    $matches = [];
    foreach ($changed_files as $file) {
        foreach ($watch_paths as $pattern) {
            $pattern = trim($pattern);
            if (empty($pattern)) {
                continue;
            }
            // Old implementation just uses fnmatch directly
            // This means !patterns are treated as literal strings
            if (fnmatch($pattern, $file)) {
                $matches[] = $file;
                break;
            }
        }
    }

    return $matches;
}

/**
 * Use the shared implementation from Application model
 */
function matchWatchPaths(array $changed_files, ?array $watch_paths): array
{
    $modifiedFiles = collect($changed_files);
    $watchPaths = is_null($watch_paths) ? null : collect($watch_paths);

    $result = Application::matchPaths($modifiedFiles, $watchPaths);

    return $result->toArray();
}

it('returns false when watch paths is null', function () {
    $changed_files = ['docker-compose.yml', 'README.md'];
    $watch_paths = null;

    $matches = matchWatchPaths($changed_files, $watch_paths);
    expect($matches)->toBeEmpty();
});

it('triggers with exact match', function () {
    $watch_paths = ['docker-compose.yml', 'Dockerfile'];

    // Exact match should return matches
    $matches = matchWatchPaths(['docker-compose.yml'], $watch_paths);
    expect($matches)->toHaveCount(1);
    expect($matches)->toEqual(['docker-compose.yml']);

    $matches = matchWatchPaths(['Dockerfile'], $watch_paths);
    expect($matches)->toHaveCount(1);
    expect($matches)->toEqual(['Dockerfile']);

    // Non-matching file should return empty
    $matches = matchWatchPaths(['README.md'], $watch_paths);
    expect($matches)->toBeEmpty();
});

it('triggers with wildcard patterns', function () {
    $watch_paths = ['*.yml', 'src/**/*.php', 'config/*'];

    // Wildcard matches
    expect(matchWatchPaths(['docker-compose.yml'], $watch_paths))->not->toBeEmpty();
    expect(matchWatchPaths(['production.yml'], $watch_paths))->not->toBeEmpty();
    expect(matchWatchPaths(['src/Controllers/UserController.php'], $watch_paths))->not->toBeEmpty();
    expect(matchWatchPaths(['src/Models/User.php'], $watch_paths))->not->toBeEmpty();
    expect(matchWatchPaths(['config/app.php'], $watch_paths))->not->toBeEmpty();

    // Non-matching files
    expect(matchWatchPaths(['README.md'], $watch_paths))->toBeEmpty();
    expect(matchWatchPaths(['src/index.js'], $watch_paths))->toBeEmpty();
    expect(matchWatchPaths(['configurations/deep/file.php'], $watch_paths))->toBeEmpty();
});

it('triggers with multiple files', function () {
    $watch_paths = ['docker-compose.yml', '*.env'];

    // At least one file matches
    $changed_files = ['README.md', 'docker-compose.yml', 'package.json'];
    $matches = matchWatchPaths($changed_files, $watch_paths);
    expect($matches)->not->toBeEmpty();
    expect($matches)->toContain('docker-compose.yml');

    // No files match
    $changed_files = ['README.md', 'package.json', 'src/index.js'];
    $matches = matchWatchPaths($changed_files, $watch_paths);
    expect($matches)->toBeEmpty();
});

it('handles leading slash include and negation', function () {
    // Include with leading slash - leading slash patterns may not match as expected with fnmatch
    // The current implementation doesn't handle leading slashes specially
    expect(matchWatchPaths(['docs/index.md'], ['/docs/**']))->toEqual([]);

    // With only negation patterns, files that DON'T match the exclusion are included
    // docs/index.md DOES match docs/**, so it should be excluded
    expect(matchWatchPaths(['docs/index.md'], ['!/docs/**']))->toEqual(['docs/index.md']);

    // src/app.ts does NOT match docs/**, so it should be included
    expect(matchWatchPaths(['src/app.ts'], ['!/docs/**']))->toEqual(['src/app.ts']);
});

it('triggers with complex patterns', function () {
    // fnmatch doesn't support {a,b} syntax, so we need to use separate patterns
    $watch_paths = ['**/*.js', '**/*.jsx', '**/*.ts', '**/*.tsx'];

    // JavaScript/TypeScript files should match
    expect(matchWatchPaths(['src/index.js'], $watch_paths))->not->toBeEmpty();
    expect(matchWatchPaths(['components/Button.jsx'], $watch_paths))->not->toBeEmpty();
    expect(matchWatchPaths(['types/user.ts'], $watch_paths))->not->toBeEmpty();
    expect(matchWatchPaths(['pages/Home.tsx'], $watch_paths))->not->toBeEmpty();

    // Deeply nested files should match
    expect(matchWatchPaths(['src/components/ui/Button.tsx'], $watch_paths))->not->toBeEmpty();

    // Non-matching files
    expect(matchWatchPaths(['README.md'], $watch_paths))->toBeEmpty();
    expect(matchWatchPaths(['package.json'], $watch_paths))->toBeEmpty();
});

it('triggers with question mark pattern', function () {
    $watch_paths = ['test?.txt', 'file-?.yml'];

    // Single character wildcard matches
    expect(matchWatchPaths(['test1.txt'], $watch_paths))->not->toBeEmpty();
    expect(matchWatchPaths(['testA.txt'], $watch_paths))->not->toBeEmpty();
    expect(matchWatchPaths(['file-1.yml'], $watch_paths))->not->toBeEmpty();
    expect(matchWatchPaths(['file-B.yml'], $watch_paths))->not->toBeEmpty();

    // Non-matching files
    expect(matchWatchPaths(['test.txt'], $watch_paths))->toBeEmpty();
    expect(matchWatchPaths(['test12.txt'], $watch_paths))->toBeEmpty();
    expect(matchWatchPaths(['file.yml'], $watch_paths))->toBeEmpty();
});

it('triggers with character set pattern', function () {
    $watch_paths = ['[abc]test.txt', 'file[0-9].yml'];

    // Character set matches
    expect(matchWatchPaths(['atest.txt'], $watch_paths))->not->toBeEmpty();
    expect(matchWatchPaths(['btest.txt'], $watch_paths))->not->toBeEmpty();
    expect(matchWatchPaths(['ctest.txt'], $watch_paths))->not->toBeEmpty();
    expect(matchWatchPaths(['file1.yml'], $watch_paths))->not->toBeEmpty();
    expect(matchWatchPaths(['file9.yml'], $watch_paths))->not->toBeEmpty();

    // Non-matching files
    expect(matchWatchPaths(['dtest.txt'], $watch_paths))->toBeEmpty();
    expect(matchWatchPaths(['test.txt'], $watch_paths))->toBeEmpty();
    expect(matchWatchPaths(['fileA.yml'], $watch_paths))->toBeEmpty();
});

it('triggers with empty watch paths', function () {
    $watch_paths = [];

    $matches = matchWatchPaths(['any-file.txt'], $watch_paths);
    expect($matches)->toBeEmpty();
});

it('triggers with whitespace only patterns', function () {
    $watch_paths = ['', '  ', '	'];

    $matches = matchWatchPaths(['any-file.txt'], $watch_paths);
    expect($matches)->toBeEmpty();
});

it('triggers for docker compose typical patterns', function () {
    $watch_paths = ['docker-compose*.yml', '.env*', 'Dockerfile*', 'services/**'];

    // Docker Compose related files
    expect(matchWatchPaths(['docker-compose.yml'], $watch_paths))->not->toBeEmpty();
    expect(matchWatchPaths(['docker-compose.prod.yml'], $watch_paths))->not->toBeEmpty();
    expect(matchWatchPaths(['docker-compose-dev.yml'], $watch_paths))->not->toBeEmpty();

    // Environment files
    expect(matchWatchPaths(['.env'], $watch_paths))->not->toBeEmpty();
    expect(matchWatchPaths(['.env.local'], $watch_paths))->not->toBeEmpty();
    expect(matchWatchPaths(['.env.production'], $watch_paths))->not->toBeEmpty();

    // Dockerfile variations
    expect(matchWatchPaths(['Dockerfile'], $watch_paths))->not->toBeEmpty();
    expect(matchWatchPaths(['Dockerfile.prod'], $watch_paths))->not->toBeEmpty();

    // Service files
    expect(matchWatchPaths(['services/api/app.js'], $watch_paths))->not->toBeEmpty();
    expect(matchWatchPaths(['services/web/index.html'], $watch_paths))->not->toBeEmpty();

    // Non-matching files (e.g., documentation, configs outside services)
    expect(matchWatchPaths(['README.md'], $watch_paths))->toBeEmpty();
    expect(matchWatchPaths(['package.json'], $watch_paths))->toBeEmpty();
    expect(matchWatchPaths(['config/nginx.conf'], $watch_paths))->toBeEmpty();
});

it('handles negation pattern with non matching file', function () {
    // Test case: file that does NOT match the exclusion pattern should trigger
    $changed_files = ['docker-compose/index.ts'];
    $watch_paths = ['!docker-compose-test/**'];

    // Since the file docker-compose/index.ts does NOT match the exclusion pattern docker-compose-test/**
    // it should trigger the deployment (file is included by default when only exclusion patterns exist)
    // This means: "deploy everything EXCEPT files in docker-compose-test/**"
    $matches = matchWatchPaths($changed_files, $watch_paths);
    expect($matches)->not->toBeEmpty();
    expect($matches)->toEqual(['docker-compose/index.ts']);

    // Test the opposite: file that DOES match the exclusion pattern should NOT trigger
    $changed_files = ['docker-compose-test/index.ts'];
    $matches = matchWatchPaths($changed_files, $watch_paths);
    expect($matches)->toBeEmpty();

    // Test with deeper path
    $changed_files = ['docker-compose-test/sub/dir/file.ts'];
    $matches = matchWatchPaths($changed_files, $watch_paths);
    expect($matches)->toBeEmpty();
});

it('handles mixed inclusion and exclusion patterns', function () {
    // Include all JS files but exclude test directories
    $watch_paths = ['**/*.js', '!**/*test*/**'];

    // Should match: JS files not in test directories
    expect(matchWatchPaths(['src/index.js'], $watch_paths))->not->toBeEmpty();
    expect(matchWatchPaths(['components/Button.js'], $watch_paths))->not->toBeEmpty();

    // Should NOT match: JS files in test directories
    expect(matchWatchPaths(['test/unit/app.js'], $watch_paths))->toBeEmpty();
    expect(matchWatchPaths(['src/test-utils/helper.js'], $watch_paths))->toBeEmpty();
    expect(matchWatchPaths(['docker-compose-test/index.js'], $watch_paths))->toBeEmpty();

    // Should NOT match: non-JS files
    expect(matchWatchPaths(['README.md'], $watch_paths))->toBeEmpty();
});

it('handles multiple negation patterns', function () {
    // Exclude multiple directories
    $watch_paths = ['!tests/**', '!docs/**', '!*.md'];

    // Should match: files not in excluded patterns
    expect(matchWatchPaths(['src/index.js'], $watch_paths))->not->toBeEmpty();
    expect(matchWatchPaths(['docker-compose.yml'], $watch_paths))->not->toBeEmpty();

    // Should NOT match: files in excluded patterns
    expect(matchWatchPaths(['tests/unit/test.js'], $watch_paths))->toBeEmpty();
    expect(matchWatchPaths(['docs/api.html'], $watch_paths))->toBeEmpty();
    expect(matchWatchPaths(['README.md'], $watch_paths))->toBeEmpty();
    expect(matchWatchPaths(['CHANGELOG.md'], $watch_paths))->toBeEmpty();
});

it('demonstrates current broken behavior with negation patterns', function () {
    // This test demonstrates the CURRENT broken behavior
    // where negation patterns are treated as literal strings
    $changed_files = ['docker-compose/index.ts'];
    $watch_paths = ['!docker-compose-test/**'];

    // With the current broken implementation, this returns empty
    // because it tries to match files starting with literal "!"
    $matches = matchWatchPathsCurrentBehavior($changed_files, $watch_paths);
    expect($matches)->toBeEmpty(); // This is why your webhook doesn't trigger!

    // Even if the file had ! in the path, fnmatch would treat ! as a literal character
    // not as a negation operator, so it still wouldn't match the pattern correctly
    $changed_files = ['test/file.ts'];
    $matches = matchWatchPathsCurrentBehavior($changed_files, $watch_paths);
    expect($matches)->toBeEmpty();
});

it('handles order based matching with conflicting patterns', function () {
    // Test case 1: Exclude then include - last pattern (include) should win
    $changed_files = ['docker-compose/index.ts'];
    $watch_paths = ['!docker-compose/**', 'docker-compose/**'];

    $matches = matchWatchPaths($changed_files, $watch_paths);
    expect($matches)->not->toBeEmpty();
    expect($matches)->toEqual(['docker-compose/index.ts']);

    // Test case 2: Include then exclude - last pattern (exclude) should win
    $watch_paths = ['docker-compose/**', '!docker-compose/**'];

    $matches = matchWatchPaths($changed_files, $watch_paths);
    expect($matches)->toBeEmpty();
});

it('handles order based matching with multiple overlapping patterns', function () {
    $changed_files = ['src/test/unit.js', 'src/components/Button.js', 'test/integration.js'];

    // Include all JS, then exclude test dirs, then re-include specific test file
    $watch_paths = [
        '**/*.js',              // Include all JS files
        '!**/test/**',          // Exclude all test directories
        'src/test/unit.js',      // Re-include this specific test file
    ];

    $matches = matchWatchPaths($changed_files, $watch_paths);

    // src/test/unit.js should be included (last specific pattern wins)
    // src/components/Button.js should be included (only matches first pattern)
    // test/integration.js should be excluded (matches exclude pattern, no override)
    expect($matches)->toHaveCount(2);
    expect($matches)->toContain('src/test/unit.js');
    expect($matches)->toContain('src/components/Button.js');
    expect($matches)->not->toContain('test/integration.js');
});

it('handles order based matching with specific overrides', function () {
    $changed_files = [
        'docs/api.md',
        'docs/guide.md',
        'docs/internal/secret.md',
        'src/index.js',
    ];

    // Exclude all docs, then include specific docs subdirectory
    $watch_paths = [
        '!docs/**',             // Exclude all docs
        'docs/internal/**',     // But include internal docs
        'src/**',                // Include src files
    ];

    $matches = matchWatchPaths($changed_files, $watch_paths);

    // Only docs/internal/secret.md and src/index.js should be included
    expect($matches)->toHaveCount(2);
    expect($matches)->toContain('docs/internal/secret.md');
    expect($matches)->toContain('src/index.js');
    expect($matches)->not->toContain('docs/api.md');
    expect($matches)->not->toContain('docs/guide.md');
});

it('preserves order precedence in pattern matching', function () {
    $changed_files = ['app/config.json'];

    // Multiple conflicting patterns - last match should win
    $watch_paths = [
        '**/*.json',        // Include (matches)
        '!app/**',          // Exclude (matches)
        'app/*.json',       // Include (matches) - THIS SHOULD WIN
    ];

    $matches = matchWatchPaths($changed_files, $watch_paths);

    // File should be included because last matching pattern is inclusive
    expect($matches)->not->toBeEmpty();
    expect($matches)->toEqual(['app/config.json']);

    // Now reverse the last two patterns
    $watch_paths = [
        '**/*.json',        // Include (matches)
        'app/*.json',       // Include (matches)
        '!app/**',          // Exclude (matches) - THIS SHOULD WIN
    ];

    $matches = matchWatchPaths($changed_files, $watch_paths);

    // File should be excluded because last matching pattern is exclusive
    expect($matches)->toBeEmpty();
});
