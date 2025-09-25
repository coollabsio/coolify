<?php

namespace Tests\Unit;

use App\Models\Application;
use PHPUnit\Framework\TestCase;

class ApplicationWatchPathsTest extends TestCase
{
    /**
     * This matches the CURRENT (broken) behavior without negation support
     * which is what the old Application.php had
     */
    private function matchWatchPathsCurrentBehavior(array $changed_files, ?array $watch_paths): array
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
    private function matchWatchPaths(array $changed_files, ?array $watch_paths): array
    {
        $modifiedFiles = collect($changed_files);
        $watchPaths = is_null($watch_paths) ? null : collect($watch_paths);

        $result = Application::matchPaths($modifiedFiles, $watchPaths);

        return $result->toArray();
    }

    public function test_is_watch_paths_triggered_returns_false_when_watch_paths_is_null()
    {
        $changed_files = ['docker-compose.yml', 'README.md'];
        $watch_paths = null;

        $matches = $this->matchWatchPaths($changed_files, $watch_paths);
        $this->assertEmpty($matches);
    }

    public function test_is_watch_paths_triggered_with_exact_match()
    {
        $watch_paths = ['docker-compose.yml', 'Dockerfile'];

        // Exact match should return matches
        $matches = $this->matchWatchPaths(['docker-compose.yml'], $watch_paths);
        $this->assertCount(1, $matches);
        $this->assertEquals(['docker-compose.yml'], $matches);

        $matches = $this->matchWatchPaths(['Dockerfile'], $watch_paths);
        $this->assertCount(1, $matches);
        $this->assertEquals(['Dockerfile'], $matches);

        // Non-matching file should return empty
        $matches = $this->matchWatchPaths(['README.md'], $watch_paths);
        $this->assertEmpty($matches);
    }

    public function test_is_watch_paths_triggered_with_wildcard_patterns()
    {
        $watch_paths = ['*.yml', 'src/**/*.php', 'config/*'];

        // Wildcard matches
        $this->assertNotEmpty($this->matchWatchPaths(['docker-compose.yml'], $watch_paths));
        $this->assertNotEmpty($this->matchWatchPaths(['production.yml'], $watch_paths));
        $this->assertNotEmpty($this->matchWatchPaths(['src/Controllers/UserController.php'], $watch_paths));
        $this->assertNotEmpty($this->matchWatchPaths(['src/Models/User.php'], $watch_paths));
        $this->assertNotEmpty($this->matchWatchPaths(['config/app.php'], $watch_paths));

        // Non-matching files
        $this->assertEmpty($this->matchWatchPaths(['README.md'], $watch_paths));
        $this->assertEmpty($this->matchWatchPaths(['src/index.js'], $watch_paths));
        $this->assertEmpty($this->matchWatchPaths(['configurations/deep/file.php'], $watch_paths));
    }

    public function test_is_watch_paths_triggered_with_multiple_files()
    {
        $watch_paths = ['docker-compose.yml', '*.env'];

        // At least one file matches
        $changed_files = ['README.md', 'docker-compose.yml', 'package.json'];
        $matches = $this->matchWatchPaths($changed_files, $watch_paths);
        $this->assertNotEmpty($matches);
        $this->assertContains('docker-compose.yml', $matches);

        // No files match
        $changed_files = ['README.md', 'package.json', 'src/index.js'];
        $matches = $this->matchWatchPaths($changed_files, $watch_paths);
        $this->assertEmpty($matches);
    }

    public function test_is_watch_paths_triggered_with_complex_patterns()
    {
        // fnmatch doesn't support {a,b} syntax, so we need to use separate patterns
        $watch_paths = ['**/*.js', '**/*.jsx', '**/*.ts', '**/*.tsx'];

        // JavaScript/TypeScript files should match
        $this->assertNotEmpty($this->matchWatchPaths(['src/index.js'], $watch_paths));
        $this->assertNotEmpty($this->matchWatchPaths(['components/Button.jsx'], $watch_paths));
        $this->assertNotEmpty($this->matchWatchPaths(['types/user.ts'], $watch_paths));
        $this->assertNotEmpty($this->matchWatchPaths(['pages/Home.tsx'], $watch_paths));

        // Deeply nested files should match
        $this->assertNotEmpty($this->matchWatchPaths(['src/components/ui/Button.tsx'], $watch_paths));

        // Non-matching files
        $this->assertEmpty($this->matchWatchPaths(['README.md'], $watch_paths));
        $this->assertEmpty($this->matchWatchPaths(['package.json'], $watch_paths));
    }

    public function test_is_watch_paths_triggered_with_question_mark_pattern()
    {
        $watch_paths = ['test?.txt', 'file-?.yml'];

        // Single character wildcard matches
        $this->assertNotEmpty($this->matchWatchPaths(['test1.txt'], $watch_paths));
        $this->assertNotEmpty($this->matchWatchPaths(['testA.txt'], $watch_paths));
        $this->assertNotEmpty($this->matchWatchPaths(['file-1.yml'], $watch_paths));
        $this->assertNotEmpty($this->matchWatchPaths(['file-B.yml'], $watch_paths));

        // Non-matching files
        $this->assertEmpty($this->matchWatchPaths(['test.txt'], $watch_paths));
        $this->assertEmpty($this->matchWatchPaths(['test12.txt'], $watch_paths));
        $this->assertEmpty($this->matchWatchPaths(['file.yml'], $watch_paths));
    }

    public function test_is_watch_paths_triggered_with_character_set_pattern()
    {
        $watch_paths = ['[abc]test.txt', 'file[0-9].yml'];

        // Character set matches
        $this->assertNotEmpty($this->matchWatchPaths(['atest.txt'], $watch_paths));
        $this->assertNotEmpty($this->matchWatchPaths(['btest.txt'], $watch_paths));
        $this->assertNotEmpty($this->matchWatchPaths(['ctest.txt'], $watch_paths));
        $this->assertNotEmpty($this->matchWatchPaths(['file1.yml'], $watch_paths));
        $this->assertNotEmpty($this->matchWatchPaths(['file9.yml'], $watch_paths));

        // Non-matching files
        $this->assertEmpty($this->matchWatchPaths(['dtest.txt'], $watch_paths));
        $this->assertEmpty($this->matchWatchPaths(['test.txt'], $watch_paths));
        $this->assertEmpty($this->matchWatchPaths(['fileA.yml'], $watch_paths));
    }

    public function test_is_watch_paths_triggered_with_empty_watch_paths()
    {
        $watch_paths = [];

        $matches = $this->matchWatchPaths(['any-file.txt'], $watch_paths);
        $this->assertEmpty($matches);
    }

    public function test_is_watch_paths_triggered_with_whitespace_only_patterns()
    {
        $watch_paths = ['', '  ', '	'];

        $matches = $this->matchWatchPaths(['any-file.txt'], $watch_paths);
        $this->assertEmpty($matches);
    }

    public function test_is_watch_paths_triggered_for_dockercompose_typical_patterns()
    {
        $watch_paths = ['docker-compose*.yml', '.env*', 'Dockerfile*', 'services/**'];

        // Docker Compose related files
        $this->assertNotEmpty($this->matchWatchPaths(['docker-compose.yml'], $watch_paths));
        $this->assertNotEmpty($this->matchWatchPaths(['docker-compose.prod.yml'], $watch_paths));
        $this->assertNotEmpty($this->matchWatchPaths(['docker-compose-dev.yml'], $watch_paths));

        // Environment files
        $this->assertNotEmpty($this->matchWatchPaths(['.env'], $watch_paths));
        $this->assertNotEmpty($this->matchWatchPaths(['.env.local'], $watch_paths));
        $this->assertNotEmpty($this->matchWatchPaths(['.env.production'], $watch_paths));

        // Dockerfile variations
        $this->assertNotEmpty($this->matchWatchPaths(['Dockerfile'], $watch_paths));
        $this->assertNotEmpty($this->matchWatchPaths(['Dockerfile.prod'], $watch_paths));

        // Service files
        $this->assertNotEmpty($this->matchWatchPaths(['services/api/app.js'], $watch_paths));
        $this->assertNotEmpty($this->matchWatchPaths(['services/web/index.html'], $watch_paths));

        // Non-matching files (e.g., documentation, configs outside services)
        $this->assertEmpty($this->matchWatchPaths(['README.md'], $watch_paths));
        $this->assertEmpty($this->matchWatchPaths(['package.json'], $watch_paths));
        $this->assertEmpty($this->matchWatchPaths(['config/nginx.conf'], $watch_paths));
    }

    public function test_negation_pattern_with_non_matching_file()
    {
        // Test case: file that does NOT match the exclusion pattern should trigger
        $changed_files = ['docker-compose/index.ts'];
        $watch_paths = ['!docker-compose-test/**'];

        // Since the file docker-compose/index.ts does NOT match the exclusion pattern docker-compose-test/**
        // it should trigger the deployment (file is included by default when only exclusion patterns exist)
        // This means: "deploy everything EXCEPT files in docker-compose-test/**"
        $matches = $this->matchWatchPaths($changed_files, $watch_paths);
        $this->assertNotEmpty($matches);
        $this->assertEquals(['docker-compose/index.ts'], $matches);

        // Test the opposite: file that DOES match the exclusion pattern should NOT trigger
        $changed_files = ['docker-compose-test/index.ts'];
        $matches = $this->matchWatchPaths($changed_files, $watch_paths);
        $this->assertEmpty($matches);

        // Test with deeper path
        $changed_files = ['docker-compose-test/sub/dir/file.ts'];
        $matches = $this->matchWatchPaths($changed_files, $watch_paths);
        $this->assertEmpty($matches);
    }

    public function test_mixed_inclusion_and_exclusion_patterns()
    {
        // Include all JS files but exclude test directories
        $watch_paths = ['**/*.js', '!**/*test*/**'];

        // Should match: JS files not in test directories
        $this->assertNotEmpty($this->matchWatchPaths(['src/index.js'], $watch_paths));
        $this->assertNotEmpty($this->matchWatchPaths(['components/Button.js'], $watch_paths));

        // Should NOT match: JS files in test directories
        $this->assertEmpty($this->matchWatchPaths(['test/unit/app.js'], $watch_paths));
        $this->assertEmpty($this->matchWatchPaths(['src/test-utils/helper.js'], $watch_paths));
        $this->assertEmpty($this->matchWatchPaths(['docker-compose-test/index.js'], $watch_paths));

        // Should NOT match: non-JS files
        $this->assertEmpty($this->matchWatchPaths(['README.md'], $watch_paths));
    }

    public function test_multiple_negation_patterns()
    {
        // Exclude multiple directories
        $watch_paths = ['!tests/**', '!docs/**', '!*.md'];

        // Should match: files not in excluded patterns
        $this->assertNotEmpty($this->matchWatchPaths(['src/index.js'], $watch_paths));
        $this->assertNotEmpty($this->matchWatchPaths(['docker-compose.yml'], $watch_paths));

        // Should NOT match: files in excluded patterns
        $this->assertEmpty($this->matchWatchPaths(['tests/unit/test.js'], $watch_paths));
        $this->assertEmpty($this->matchWatchPaths(['docs/api.html'], $watch_paths));
        $this->assertEmpty($this->matchWatchPaths(['README.md'], $watch_paths));
        $this->assertEmpty($this->matchWatchPaths(['CHANGELOG.md'], $watch_paths));
    }

    public function test_current_broken_behavior_with_negation_patterns()
    {
        // This test demonstrates the CURRENT broken behavior
        // where negation patterns are treated as literal strings
        $changed_files = ['docker-compose/index.ts'];
        $watch_paths = ['!docker-compose-test/**'];

        // With the current broken implementation, this returns empty
        // because it tries to match files starting with literal "!"
        $matches = $this->matchWatchPathsCurrentBehavior($changed_files, $watch_paths);
        $this->assertEmpty($matches); // This is why your webhook doesn't trigger!

        // Even if the file had ! in the path, fnmatch would treat ! as a literal character
        // not as a negation operator, so it still wouldn't match the pattern correctly
        $changed_files = ['test/file.ts'];
        $matches = $this->matchWatchPathsCurrentBehavior($changed_files, $watch_paths);
        $this->assertEmpty($matches);
    }

    public function test_order_based_matching_with_conflicting_patterns()
    {
        // Test case 1: Exclude then include - last pattern (include) should win
        $changed_files = ['docker-compose/index.ts'];
        $watch_paths = ['!docker-compose/**', 'docker-compose/**'];

        $matches = $this->matchWatchPaths($changed_files, $watch_paths);
        $this->assertNotEmpty($matches);
        $this->assertEquals(['docker-compose/index.ts'], $matches);

        // Test case 2: Include then exclude - last pattern (exclude) should win
        $watch_paths = ['docker-compose/**', '!docker-compose/**'];

        $matches = $this->matchWatchPaths($changed_files, $watch_paths);
        $this->assertEmpty($matches);
    }

    public function test_order_based_matching_with_multiple_overlapping_patterns()
    {
        $changed_files = ['src/test/unit.js', 'src/components/Button.js', 'test/integration.js'];

        // Include all JS, then exclude test dirs, then re-include specific test file
        $watch_paths = [
            '**/*.js',              // Include all JS files
            '!**/test/**',          // Exclude all test directories
            'src/test/unit.js',      // Re-include this specific test file
        ];

        $matches = $this->matchWatchPaths($changed_files, $watch_paths);

        // src/test/unit.js should be included (last specific pattern wins)
        // src/components/Button.js should be included (only matches first pattern)
        // test/integration.js should be excluded (matches exclude pattern, no override)
        $this->assertCount(2, $matches);
        $this->assertContains('src/test/unit.js', $matches);
        $this->assertContains('src/components/Button.js', $matches);
        $this->assertNotContains('test/integration.js', $matches);
    }

    public function test_order_based_matching_with_specific_overrides()
    {
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

        $matches = $this->matchWatchPaths($changed_files, $watch_paths);

        // Only docs/internal/secret.md and src/index.js should be included
        $this->assertCount(2, $matches);
        $this->assertContains('docs/internal/secret.md', $matches);
        $this->assertContains('src/index.js', $matches);
        $this->assertNotContains('docs/api.md', $matches);
        $this->assertNotContains('docs/guide.md', $matches);
    }

    public function test_order_based_matching_preserves_order_precedence()
    {
        $changed_files = ['app/config.json'];

        // Multiple conflicting patterns - last match should win
        $watch_paths = [
            '**/*.json',        // Include (matches)
            '!app/**',          // Exclude (matches)
            'app/*.json',       // Include (matches) - THIS SHOULD WIN
        ];

        $matches = $this->matchWatchPaths($changed_files, $watch_paths);

        // File should be included because last matching pattern is inclusive
        $this->assertNotEmpty($matches);
        $this->assertEquals(['app/config.json'], $matches);

        // Now reverse the last two patterns
        $watch_paths = [
            '**/*.json',        // Include (matches)
            'app/*.json',       // Include (matches)
            '!app/**',          // Exclude (matches) - THIS SHOULD WIN
        ];

        $matches = $this->matchWatchPaths($changed_files, $watch_paths);

        // File should be excluded because last matching pattern is exclusive
        $this->assertEmpty($matches);
    }
}
