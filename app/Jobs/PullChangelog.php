<?php

namespace App\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PullChangelog implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 30;

    public function __construct()
    {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        try {
            // Fetch from CDN instead of GitHub API to avoid rate limits
            $cdnUrl = config('constants.coolify.releases_url');

            $response = Http::retry(3, 1000)
                ->timeout(30)
                ->get($cdnUrl);

            if ($response->successful()) {
                $releases = $response->json();

                // Limit to 10 releases for processing (same as before)
                $releases = array_slice($releases, 0, 10);

                $changelog = $this->transformReleasesToChangelog($releases);

                // Group entries by month and save them
                $this->saveChangelogEntries($changelog);
            } else {
                // Log error instead of sending notification
                Log::error('PullChangelogFromGitHub: Failed to fetch from CDN', [
                    'status' => $response->status(),
                    'url' => $cdnUrl,
                ]);
            }
        } catch (\Throwable $e) {
            // Log error instead of sending notification
            Log::error('PullChangelogFromGitHub: Exception occurred', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function transformReleasesToChangelog(array $releases): array
    {
        $entries = [];

        foreach ($releases as $release) {
            // Skip drafts and pre-releases if desired
            if ($release['draft']) {
                continue;
            }

            $publishedAt = Carbon::parse($release['published_at']);

            $entry = [
                'tag_name' => $release['tag_name'],
                'title' => $release['name'] ?: $release['tag_name'],
                'content' => $release['body'] ?: 'No release notes available.',
                'published_at' => $publishedAt->toISOString(),
            ];

            $entries[] = $entry;
        }

        return $entries;
    }

    private function saveChangelogEntries(array $entries): void
    {
        // Create changelogs directory if it doesn't exist
        $changelogsDir = base_path('changelogs');
        if (! File::exists($changelogsDir)) {
            File::makeDirectory($changelogsDir, 0755, true);
        }

        // Group entries by year-month
        $groupedEntries = [];
        foreach ($entries as $entry) {
            $date = Carbon::parse($entry['published_at']);
            $monthKey = $date->format('Y-m');

            if (! isset($groupedEntries[$monthKey])) {
                $groupedEntries[$monthKey] = [];
            }

            $groupedEntries[$monthKey][] = $entry;
        }

        // Save each month's entries to separate files
        foreach ($groupedEntries as $month => $monthEntries) {
            // Sort entries by published date (newest first)
            usort($monthEntries, function ($a, $b) {
                return Carbon::parse($b['published_at'])->timestamp - Carbon::parse($a['published_at'])->timestamp;
            });

            $monthData = [
                'entries' => $monthEntries,
                'last_updated' => now()->toISOString(),
            ];

            $filePath = base_path("changelogs/{$month}.json");
            File::put($filePath, json_encode($monthData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

    }
}
