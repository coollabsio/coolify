<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;

class InitChangelog extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'changelog:init {month? : Month in YYYY-MM format (defaults to current month)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Initialize a new monthly changelog file with example structure';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $month = $this->argument('month') ?: Carbon::now()->format('Y-m');

        // Validate month format
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $this->error('Invalid month format. Use YYYY-MM format with valid months 01-12 (e.g., 2025-08)');

            return self::FAILURE;
        }

        $changelogsDir = base_path('changelogs');
        $filePath = $changelogsDir."/{$month}.json";

        // Create changelogs directory if it doesn't exist
        if (! is_dir($changelogsDir)) {
            mkdir($changelogsDir, 0755, true);
            $this->info("Created changelogs directory: {$changelogsDir}");
        }

        // Check if file already exists
        if (file_exists($filePath)) {
            if (! $this->confirm("File {$month}.json already exists. Overwrite?")) {
                $this->info('Operation cancelled');

                return self::SUCCESS;
            }
        }

        // Parse the month for example data
        $carbonMonth = Carbon::createFromFormat('Y-m', $month);
        $monthName = $carbonMonth->format('F Y');
        $sampleDate = $carbonMonth->addDays(14)->toISOString(); // Mid-month

        // Get version from config
        $version = 'v'.config('constants.coolify.version');

        // Create example changelog structure
        $exampleData = [
            'entries' => [
                [
                    'version' => $version,
                    'title' => 'Example Feature Release',
                    'content' => "This is an example changelog entry for {$monthName}. Replace this with your actual release notes. Include details about new features, improvements, bug fixes, and any breaking changes.",
                    'published_at' => $sampleDate,
                ],
            ],
        ];

        // Write the file
        $jsonContent = json_encode($exampleData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (file_put_contents($filePath, $jsonContent) === false) {
            $this->error("Failed to create changelog file: {$filePath}");

            return self::FAILURE;
        }

        $this->info("✅ Created changelog file: changelogs/{$month}.json");
        $this->line("   Example entry created for {$monthName}");
        $this->line('   Edit the file to add your actual changelog entries');

        // Show the file contents
        if ($this->option('verbose')) {
            $this->newLine();
            $this->line('File contents:');
            $this->line($jsonContent);
        }

        return self::SUCCESS;
    }
}
