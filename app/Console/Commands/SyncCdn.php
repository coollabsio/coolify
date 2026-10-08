<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;

class SyncCdn extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:cdn {--purge : Purge the Cloudflare cache for the CDN files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync CDN files to the coollabs-cdn GitHub repository';

    protected function removeTemporaryDirectory(string $tmpDir): void
    {
        $temporaryRoot = realpath(sys_get_temp_dir());
        $temporaryDirectory = realpath($tmpDir);

        if ($temporaryRoot === false || $temporaryDirectory === false) {
            return;
        }

        $expectedPrefix = rtrim($temporaryRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'coollabs-cdn-';
        if (! str_starts_with($temporaryDirectory, $expectedPrefix)) {
            return;
        }

        File::deleteDirectory($temporaryDirectory);
    }

    /**
     * Purge the given CDN URLs from the Cloudflare cache.
     *
     * @param  array<int, string>  $urls
     */
    private function purgeCloudflareCache(array $urls): bool
    {
        $apiToken = config('constants.cloudflare.api_token');
        $zoneId = config('constants.cloudflare.zone_id');

        if (blank($apiToken) || blank($zoneId)) {
            $this->error('CLOUDFLARE_API_TOKEN and CLOUDFLARE_ZONE_ID must be set to purge the Cloudflare cache.');

            return false;
        }

        foreach ($urls as $url) {
            $this->info('Purging: '.$url);
        }

        try {
            $response = Http::withToken($apiToken)
                ->timeout(30)
                ->post("https://api.cloudflare.com/client/v4/zones/{$zoneId}/purge_cache", [
                    'files' => array_values($urls),
                ]);
        } catch (\Throwable $e) {
            $this->error('Failed to purge Cloudflare cache: '.$e->getMessage());

            return false;
        }

        if (! $response->successful() || $response->json('success') !== true) {
            $this->error('Failed to purge Cloudflare cache: '.$response->body());

            return false;
        }

        $this->info('Cloudflare cache purged.');

        return true;
    }

    /**
     * Fetch GitHub releases and sync to GitHub repository
     */
    private function syncReleasesToGitHubRepo(array $files, bool $nightly = false): bool
    {
        $this->info('Fetching releases from GitHub...');
        try {
            $response = Http::timeout(30)
                ->get('https://api.github.com/repos/coollabsio/coolify/releases', [
                    'per_page' => 30,  // Fetch more releases for better changelog
                ]);

            if (! $response->successful()) {
                $this->error('Failed to fetch releases from GitHub: '.$response->status());

                return false;
            }

            $releasesFile = tempnam(sys_get_temp_dir(), 'coolify-releases-');
            if ($releasesFile === false || file_put_contents($releasesFile, json_encode($response->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
                $this->error('Failed to create temporary releases.json.');

                return false;
            }

            $files[$releasesFile] = $nightly ? 'json/coolify/nightly/releases.json' : 'json/coolify/releases.json';

            try {
                return $this->syncFilesToGitHubRepo($files, $nightly);
            } finally {
                @unlink($releasesFile);
            }
        } catch (\Throwable $e) {
            $this->error('Error syncing releases: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Sync install.sh, docker-compose, and env files to GitHub repository via PR
     */
    private function syncFilesToGitHubRepo(array $files, bool $nightly = false): bool
    {
        $envLabel = $nightly ? 'NIGHTLY' : 'PRODUCTION';
        $this->info("Syncing $envLabel files to GitHub repository...");
        try {
            $timestamp = time();
            $tmpDir = sys_get_temp_dir().'/coollabs-cdn-files-'.$timestamp;
            $branchName = 'update-files-'.$timestamp;

            // Clone the repository
            $this->info('Cloning coollabs-cdn repository...');
            $output = [];
            exec('gh repo clone coollabsio/coollabs-cdn '.escapeshellarg($tmpDir).' 2>&1', $output, $returnCode);
            if ($returnCode !== 0) {
                $this->error('Failed to clone repository: '.implode("\n", $output));

                return false;
            }

            // Create feature branch
            $this->info('Creating feature branch...');
            $output = [];
            exec('cd '.escapeshellarg($tmpDir).' && git checkout -b '.escapeshellarg($branchName).' 2>&1', $output, $returnCode);
            if ($returnCode !== 0) {
                $this->error('Failed to create branch: '.implode("\n", $output));
                $this->removeTemporaryDirectory($tmpDir);

                return false;
            }

            // Copy each file to its target path in the CDN repo
            $copiedFiles = [];
            foreach ($files as $sourceFile => $targetPath) {
                if (! file_exists($sourceFile)) {
                    $this->warn("Source file not found, skipping: $sourceFile");

                    continue;
                }

                $destPath = "$tmpDir/$targetPath";
                $destDir = dirname($destPath);

                if (! is_dir($destDir)) {
                    if (! mkdir($destDir, 0755, true)) {
                        $this->error("Failed to create directory: $destDir");
                        $this->removeTemporaryDirectory($tmpDir);

                        return false;
                    }
                }

                if (copy($sourceFile, $destPath) === false) {
                    $this->error("Failed to copy $sourceFile to $destPath");
                    $this->removeTemporaryDirectory($tmpDir);

                    return false;
                }

                $copiedFiles[] = $targetPath;
                $this->info("Copied: $targetPath");
            }

            if (empty($copiedFiles)) {
                $this->warn('No files were copied. Nothing to commit.');
                $this->removeTemporaryDirectory($tmpDir);

                return true;
            }

            // Stage all copied files
            $this->info('Staging changes...');
            $output = [];
            $stageCmd = 'cd '.escapeshellarg($tmpDir).' && git add '.implode(' ', array_map('escapeshellarg', $copiedFiles)).' 2>&1';
            exec($stageCmd, $output, $returnCode);
            if ($returnCode !== 0) {
                $this->error('Failed to stage changes: '.implode("\n", $output));
                $this->removeTemporaryDirectory($tmpDir);

                return false;
            }

            // Check for changes
            $this->info('Checking for changes...');
            $changedFiles = [];
            exec('cd '.escapeshellarg($tmpDir).' && git diff --cached --name-only 2>&1', $changedFiles, $returnCode);
            if ($returnCode !== 0) {
                $this->error('Failed to check changed files: '.implode("\n", $changedFiles));
                $this->removeTemporaryDirectory($tmpDir);

                return false;
            }

            $changedFiles = array_values(array_filter($changedFiles));
            if (empty($changedFiles)) {
                $this->info('All files are already up to date. No changes to commit.');
                $this->removeTemporaryDirectory($tmpDir);

                return true;
            }

            // Commit changes
            $commitMessage = "Update $envLabel files (install.sh, docker-compose, env) - ".date('Y-m-d H:i:s');
            $output = [];
            exec('cd '.escapeshellarg($tmpDir).' && git commit -m '.escapeshellarg($commitMessage).' 2>&1', $output, $returnCode);
            if ($returnCode !== 0) {
                $this->error('Failed to commit changes: '.implode("\n", $output));
                $this->removeTemporaryDirectory($tmpDir);

                return false;
            }

            // Push to remote
            $this->info('Pushing branch to remote...');
            $output = [];
            exec('cd '.escapeshellarg($tmpDir).' && git push origin '.escapeshellarg($branchName).' 2>&1', $output, $returnCode);
            if ($returnCode !== 0) {
                $this->error('Failed to push branch: '.implode("\n", $output));
                $this->removeTemporaryDirectory($tmpDir);

                return false;
            }

            // Create pull request
            $this->info('Creating pull request...');
            $prTitle = "Update $envLabel files - ".date('Y-m-d H:i:s');
            $fileList = implode("\n- ", $changedFiles);
            $prBody = "Automated update of $envLabel files:\n- $fileList";
            $prCommand = 'gh pr create --repo coollabsio/coollabs-cdn --title '.escapeshellarg($prTitle).' --body '.escapeshellarg($prBody).' --base main --head '.escapeshellarg($branchName).' 2>&1';
            $output = [];
            exec($prCommand, $output, $returnCode);

            // Clean up
            $this->removeTemporaryDirectory($tmpDir);

            if ($returnCode !== 0) {
                $this->error('Failed to create PR: '.implode("\n", $output));

                return false;
            }

            $this->info('Pull request created successfully!');
            if (! empty($output)) {
                $this->info('PR URL: '.implode("\n", $output));
            }
            $this->info('Files synced: '.count($changedFiles));

            return true;
        } catch (\Throwable $e) {
            $this->error('Error syncing files to GitHub: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $nightly = select(
            label: 'Which environment would you like to sync?',
            options: [
                'production' => 'Production',
                'nightly' => 'Nightly',
            ],
            default: 'production',
        ) === 'nightly';
        $cdn = 'https://cdn.coollabs.io';

        $parent_dir = realpath(dirname(__FILE__).'/../../..');

        $compose_file = 'docker-compose.yml';
        $compose_file_prod = 'docker-compose.prod.yml';
        $install_script = 'install.sh';
        $upgrade_script = 'upgrade.sh';
        $upgrade_postgres_script = 'upgrade-postgres.sh';
        $production_env = '.env.production';
        $service_template = config('constants.services.file_name');
        $versions = 'versions.json';

        $compose_file_location = "$parent_dir/$compose_file";
        $compose_file_prod_location = "$parent_dir/$compose_file_prod";
        $install_script_location = "$parent_dir/scripts/install.sh";
        $upgrade_script_location = "$parent_dir/scripts/upgrade.sh";
        $upgrade_postgres_script_location = "$parent_dir/scripts/upgrade-postgres.sh";
        $production_env_location = "$parent_dir/.env.production";
        $service_template_location = "$parent_dir/templates/$service_template";
        $versions_location = "$parent_dir/$versions";

        try {
            if ($nightly) {
                $compose_file_location = "$parent_dir/other/nightly/$compose_file";
                $compose_file_prod_location = "$parent_dir/other/nightly/$compose_file_prod";
                $production_env_location = "$parent_dir/other/nightly/$production_env";
                $upgrade_script_location = "$parent_dir/other/nightly/$upgrade_script";
                $upgrade_postgres_script_location = "$parent_dir/other/nightly/$upgrade_postgres_script";
                $install_script_location = "$parent_dir/other/nightly/$install_script";
                $versions_location = "$parent_dir/other/nightly/$versions";
            }
            if ($nightly) {
                $files = [
                    $versions_location => 'json/coolify/nightly/versions.json',
                    $compose_file_location => 'json/coolify/nightly/docker-compose.yml',
                    $compose_file_prod_location => 'json/coolify/nightly/docker-compose.prod.yml',
                    $production_env_location => 'json/coolify/nightly/.env.production',
                    $install_script_location => 'json/coolify/nightly/install.sh',
                    $upgrade_script_location => 'json/coolify/nightly/upgrade.sh',
                    $upgrade_postgres_script_location => 'json/coolify/nightly/upgrade-postgres.sh',
                    $service_template_location => 'json/coolify/nightly/service-templates-latest.json',
                ];
            } else {
                $files = [
                    $versions_location => 'json/coolify/versions.json',
                    $compose_file_location => 'json/coolify/docker-compose.yml',
                    $compose_file_prod_location => 'json/coolify/docker-compose.prod.yml',
                    $production_env_location => 'json/coolify/.env.production',
                    $install_script_location => 'json/coolify/install.sh',
                    $upgrade_script_location => 'json/coolify/upgrade.sh',
                    $upgrade_postgres_script_location => 'json/coolify/upgrade-postgres.sh',
                    $service_template_location => 'json/coolify/service-templates-latest.json',
                ];
            }

            $releasesTarget = $nightly ? 'json/coolify/nightly/releases.json' : 'json/coolify/releases.json';

            if ($this->option('purge')) {
                $urls = array_map(
                    fn (string $targetPath) => $cdn.'/'.substr($targetPath, strlen('json/')),
                    [$releasesTarget, ...array_values($files)],
                );
                if ($nightly) {
                    // Nightly scripts still download from the legacy /coolify-nightly/ path, which Cloudflare caches separately.
                    $urls = [...$urls, ...str_replace('/coolify/nightly/', '/coolify-nightly/', $urls)];
                }

                return $this->purgeCloudflareCache($urls) ? self::SUCCESS : self::FAILURE;
            }

            $envLabel = $nightly ? 'NIGHTLY' : 'PRODUCTION';
            $this->info("About to sync $envLabel releases, versions, compose, and environment files to GitHub repository.");

            $options = [$releasesTarget, ...array_values($files)];
            $selectedFiles = multiselect(
                label: 'Which files would you like to sync?',
                options: $options,
                default: $options,
                required: true,
                scroll: count($options),
            );

            $includeReleases = in_array($releasesTarget, $selectedFiles, true);
            $files = array_filter(
                $files,
                fn (string $targetPath) => in_array($targetPath, $selectedFiles, true),
            );

            $synced = $includeReleases
                ? $this->syncReleasesToGitHubRepo($files, $nightly)
                : $this->syncFilesToGitHubRepo($files, $nightly);

            if ($synced) {
                $this->info('After the CDN pull request is merged and deployed, run "php artisan sync:cdn --purge" to purge the Cloudflare cache.');
            }
        } catch (\Throwable $e) {
            $this->error('Error: '.$e->getMessage());
        }
    }
}
