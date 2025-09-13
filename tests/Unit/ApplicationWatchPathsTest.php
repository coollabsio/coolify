<?php

namespace Tests\Unit;

use App\Models\Application;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ApplicationWatchPathsTest extends TestCase
{
    public function test_is_watch_paths_triggered_returns_false_when_watch_paths_is_null()
    {
        $application = new Application();
        $application->watch_paths = null;

        $modified_files = collect(['docker-compose.yml', 'README.md']);

        $this->assertFalse($application->isWatchPathsTriggered($modified_files));
    }

    public function test_is_watch_paths_triggered_with_exact_match()
    {
        $application = new Application();
        $application->watch_paths = "docker-compose.yml\nDockerfile";

        // Exact match should return true
        $this->assertTrue($application->isWatchPathsTriggered(collect(['docker-compose.yml'])));
        $this->assertTrue($application->isWatchPathsTriggered(collect(['Dockerfile'])));

        // Non-matching file should return false
        $this->assertFalse($application->isWatchPathsTriggered(collect(['README.md'])));
    }

    public function test_is_watch_paths_triggered_with_wildcard_patterns()
    {
        $application = new Application();
        $application->watch_paths = "*.yml\nsrc/**/*.php\nconfig/*";

        // Wildcard matches
        $this->assertTrue($application->isWatchPathsTriggered(collect(['docker-compose.yml'])));
        $this->assertTrue($application->isWatchPathsTriggered(collect(['production.yml'])));
        $this->assertTrue($application->isWatchPathsTriggered(collect(['src/Controllers/UserController.php'])));
        $this->assertTrue($application->isWatchPathsTriggered(collect(['src/Models/User.php'])));
        $this->assertTrue($application->isWatchPathsTriggered(collect(['config/app.php'])));

        // Non-matching files
        $this->assertFalse($application->isWatchPathsTriggered(collect(['README.md'])));
        $this->assertFalse($application->isWatchPathsTriggered(collect(['src/index.js'])));
        $this->assertFalse($application->isWatchPathsTriggered(collect(['configurations/deep/file.php'])));
    }

    public function test_is_watch_paths_triggered_with_multiple_files()
    {
        $application = new Application();
        $application->watch_paths = "docker-compose.yml\n*.env";

        // At least one file matches
        $modified_files = collect(['README.md', 'docker-compose.yml', 'package.json']);
        $this->assertTrue($application->isWatchPathsTriggered($modified_files));

        // No files match
        $modified_files = collect(['README.md', 'package.json', 'src/index.js']);
        $this->assertFalse($application->isWatchPathsTriggered($modified_files));
    }

    public function test_is_watch_paths_triggered_with_complex_patterns()
    {
        $application = new Application();
        // fnmatch doesn't support {a,b} syntax, so we need to use separate patterns
        $application->watch_paths = "**/*.js\n**/*.jsx\n**/*.ts\n**/*.tsx";

        // JavaScript/TypeScript files should match
        $this->assertTrue($application->isWatchPathsTriggered(collect(['src/index.js'])));
        $this->assertTrue($application->isWatchPathsTriggered(collect(['components/Button.jsx'])));
        $this->assertTrue($application->isWatchPathsTriggered(collect(['types/user.ts'])));
        $this->assertTrue($application->isWatchPathsTriggered(collect(['pages/Home.tsx'])));

        // Deeply nested files should match
        $this->assertTrue($application->isWatchPathsTriggered(collect(['src/components/ui/Button.tsx'])));

        // Non-matching files
        $this->assertFalse($application->isWatchPathsTriggered(collect(['README.md'])));
        $this->assertFalse($application->isWatchPathsTriggered(collect(['package.json'])));
    }

    public function test_is_watch_paths_triggered_with_question_mark_pattern()
    {
        $application = new Application();
        $application->watch_paths = "test?.txt\nfile-?.yml";

        // Single character wildcard matches
        $this->assertTrue($application->isWatchPathsTriggered(collect(['test1.txt'])));
        $this->assertTrue($application->isWatchPathsTriggered(collect(['testA.txt'])));
        $this->assertTrue($application->isWatchPathsTriggered(collect(['file-1.yml'])));
        $this->assertTrue($application->isWatchPathsTriggered(collect(['file-B.yml'])));

        // Non-matching files
        $this->assertFalse($application->isWatchPathsTriggered(collect(['test.txt'])));
        $this->assertFalse($application->isWatchPathsTriggered(collect(['test12.txt'])));
        $this->assertFalse($application->isWatchPathsTriggered(collect(['file.yml'])));
    }

    public function test_is_watch_paths_triggered_with_character_set_pattern()
    {
        $application = new Application();
        $application->watch_paths = "[abc]test.txt\nfile[0-9].yml";

        // Character set matches
        $this->assertTrue($application->isWatchPathsTriggered(collect(['atest.txt'])));
        $this->assertTrue($application->isWatchPathsTriggered(collect(['btest.txt'])));
        $this->assertTrue($application->isWatchPathsTriggered(collect(['ctest.txt'])));
        $this->assertTrue($application->isWatchPathsTriggered(collect(['file1.yml'])));
        $this->assertTrue($application->isWatchPathsTriggered(collect(['file9.yml'])));

        // Non-matching files
        $this->assertFalse($application->isWatchPathsTriggered(collect(['dtest.txt'])));
        $this->assertFalse($application->isWatchPathsTriggered(collect(['test.txt'])));
        $this->assertFalse($application->isWatchPathsTriggered(collect(['fileA.yml'])));
    }

    public function test_is_watch_paths_triggered_with_empty_watch_paths()
    {
        $application = new Application();
        $application->watch_paths = '';

        $this->assertFalse($application->isWatchPathsTriggered(collect(['any-file.txt'])));
    }

    public function test_is_watch_paths_triggered_with_whitespace_only_patterns()
    {
        $application = new Application();
        $application->watch_paths = "\n  \n\t\n";

        $this->assertFalse($application->isWatchPathsTriggered(collect(['any-file.txt'])));
    }

    public function test_is_watch_paths_triggered_for_dockercompose_typical_patterns()
    {
        $application = new Application();
        $application->watch_paths = "docker-compose*.yml\n.env*\nDockerfile*\nservices/**";

        // Docker Compose related files
        $this->assertTrue($application->isWatchPathsTriggered(collect(['docker-compose.yml'])));
        $this->assertTrue($application->isWatchPathsTriggered(collect(['docker-compose.prod.yml'])));
        $this->assertTrue($application->isWatchPathsTriggered(collect(['docker-compose-dev.yml'])));
        
        // Environment files
        $this->assertTrue($application->isWatchPathsTriggered(collect(['.env'])));
        $this->assertTrue($application->isWatchPathsTriggered(collect(['.env.local'])));
        $this->assertTrue($application->isWatchPathsTriggered(collect(['.env.production'])));
        
        // Dockerfile variations
        $this->assertTrue($application->isWatchPathsTriggered(collect(['Dockerfile'])));
        $this->assertTrue($application->isWatchPathsTriggered(collect(['Dockerfile.prod'])));
        
        // Service files
        $this->assertTrue($application->isWatchPathsTriggered(collect(['services/api/app.js'])));
        $this->assertTrue($application->isWatchPathsTriggered(collect(['services/web/index.html'])));

        // Non-matching files (e.g., documentation, configs outside services)
        $this->assertFalse($application->isWatchPathsTriggered(collect(['README.md'])));
        $this->assertFalse($application->isWatchPathsTriggered(collect(['package.json'])));
        $this->assertFalse($application->isWatchPathsTriggered(collect(['config/nginx.conf'])));
    }
}