<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;

use function Laravel\Prompts\confirm;

class SyncBunny extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:bunny {--templates} {--release} {--github-releases} {--github-versions} {--nightly}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync files to BunnyCDN';

    /**
     * Fetch GitHub releases and sync to GitHub repository
     */
    private function syncReleasesToGitHubRepo(): bool
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

            $releases = $response->json();
            $timestamp = time();
            $tmpDir = sys_get_temp_dir().'/coolify-cdn-'.$timestamp;
            $branchName = 'update-releases-'.$timestamp;

            // Clone the repository
            $this->info('Cloning coolify-cdn repository...');
            exec('gh repo clone coollabsio/coolify-cdn '.escapeshellarg($tmpDir).' 2>&1', $output, $returnCode);
            if ($returnCode !== 0) {
                $this->error('Failed to clone repository: '.implode("\n", $output));

                return false;
            }

            // Create feature branch
            $this->info('Creating feature branch...');
            exec('cd '.escapeshellarg($tmpDir).' && git checkout -b '.escapeshellarg($branchName).' 2>&1', $output, $returnCode);
            if ($returnCode !== 0) {
                $this->error('Failed to create branch: '.implode("\n", $output));
                exec('rm -rf '.escapeshellarg($tmpDir));

                return false;
            }

            // Write releases.json
            $this->info('Writing releases.json...');
            $releasesPath = "$tmpDir/json/releases.json";
            $releasesDir = dirname($releasesPath);

            // Ensure directory exists
            if (! is_dir($releasesDir)) {
                $this->info("Creating directory: $releasesDir");
                if (! mkdir($releasesDir, 0755, true)) {
                    $this->error("Failed to create directory: $releasesDir");
                    exec('rm -rf '.escapeshellarg($tmpDir));

                    return false;
                }
            }

            $jsonContent = json_encode($releases, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $bytesWritten = file_put_contents($releasesPath, $jsonContent);

            if ($bytesWritten === false) {
                $this->error("Failed to write releases.json to: $releasesPath");
                $this->error('Possible reasons: permission denied or disk full.');
                exec('rm -rf '.escapeshellarg($tmpDir));

                return false;
            }

            // Stage and commit
            $this->info('Committing changes...');
            exec('cd '.escapeshellarg($tmpDir).' && git add json/releases.json 2>&1', $output, $returnCode);
            if ($returnCode !== 0) {
                $this->error('Failed to stage changes: '.implode("\n", $output));
                exec('rm -rf '.escapeshellarg($tmpDir));

                return false;
            }

            $this->info('Checking for changes...');
            $statusOutput = [];
            exec('cd '.escapeshellarg($tmpDir).' && git status --porcelain json/releases.json 2>&1', $statusOutput, $returnCode);
            if ($returnCode !== 0) {
                $this->error('Failed to check repository status: '.implode("\n", $statusOutput));
                exec('rm -rf '.escapeshellarg($tmpDir));

                return false;
            }

            if (empty(array_filter($statusOutput))) {
                $this->info('Releases are already up to date. No changes to commit.');
                exec('rm -rf '.escapeshellarg($tmpDir));

                return true;
            }

            $commitMessage = 'Update releases.json with latest releases - '.date('Y-m-d H:i:s');
            $output = [];
            exec('cd '.escapeshellarg($tmpDir).' && git commit -m '.escapeshellarg($commitMessage).' 2>&1', $output, $returnCode);
            if ($returnCode !== 0) {
                $this->error('Failed to commit changes: '.implode("\n", $output));
                exec('rm -rf '.escapeshellarg($tmpDir));

                return false;
            }

            // Push to remote
            $this->info('Pushing branch to remote...');
            exec('cd '.escapeshellarg($tmpDir).' && git push origin '.escapeshellarg($branchName).' 2>&1', $output, $returnCode);
            if ($returnCode !== 0) {
                $this->error('Failed to push branch: '.implode("\n", $output));
                exec('rm -rf '.escapeshellarg($tmpDir));

                return false;
            }

            // Create pull request
            $this->info('Creating pull request...');
            $prTitle = 'Update releases.json - '.date('Y-m-d H:i:s');
            $prBody = 'Automated update of releases.json with latest '.count($releases).' releases from GitHub API';
            $prCommand = 'gh pr create --repo coollabsio/coolify-cdn --title '.escapeshellarg($prTitle).' --body '.escapeshellarg($prBody).' --base main --head '.escapeshellarg($branchName).' 2>&1';
            exec($prCommand, $output, $returnCode);

            // Clean up
            exec('rm -rf '.escapeshellarg($tmpDir));

            if ($returnCode !== 0) {
                $this->error('Failed to create PR: '.implode("\n", $output));

                return false;
            }

            $this->info('Pull request created successfully!');
            if (! empty($output)) {
                $this->info('PR Output: '.implode("\n", $output));
            }
            $this->info('Total releases synced: '.count($releases));

            return true;
        } catch (\Throwable $e) {
            $this->error('Error syncing releases: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Sync versions.json to GitHub repository via PR
     */
    private function syncVersionsToGitHubRepo(string $versionsLocation, bool $nightly = false): bool
    {
        $this->info('Syncing versions.json to GitHub repository...');
        try {
            if (! file_exists($versionsLocation)) {
                $this->error("versions.json not found at: $versionsLocation");

                return false;
            }

            $file = file_get_contents($versionsLocation);
            $json = json_decode($file, true);
            $actualVersion = data_get($json, 'coolify.v4.version');

            $timestamp = time();
            $tmpDir = sys_get_temp_dir().'/coolify-cdn-versions-'.$timestamp;
            $branchName = 'update-versions-'.$timestamp;
            $targetPath = $nightly ? 'json/versions-nightly.json' : 'json/versions.json';

            // Clone the repository
            $this->info('Cloning coolify-cdn repository...');
            exec('gh repo clone coollabsio/coolify-cdn '.escapeshellarg($tmpDir).' 2>&1', $output, $returnCode);
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
                exec('rm -rf '.escapeshellarg($tmpDir));

                return false;
            }

            // Write versions.json
            $this->info('Writing versions.json...');
            $versionsPath = "$tmpDir/$targetPath";
            $versionsDir = dirname($versionsPath);

            // Ensure directory exists
            if (! is_dir($versionsDir)) {
                $this->info("Creating directory: $versionsDir");
                if (! mkdir($versionsDir, 0755, true)) {
                    $this->error("Failed to create directory: $versionsDir");
                    exec('rm -rf '.escapeshellarg($tmpDir));

                    return false;
                }
            }

            $jsonContent = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $bytesWritten = file_put_contents($versionsPath, $jsonContent);

            if ($bytesWritten === false) {
                $this->error("Failed to write versions.json to: $versionsPath");
                $this->error('Possible reasons: permission denied or disk full.');
                exec('rm -rf '.escapeshellarg($tmpDir));

                return false;
            }

            // Stage and commit
            $this->info('Committing changes...');
            $output = [];
            exec('cd '.escapeshellarg($tmpDir).' && git add '.escapeshellarg($targetPath).' 2>&1', $output, $returnCode);
            if ($returnCode !== 0) {
                $this->error('Failed to stage changes: '.implode("\n", $output));
                exec('rm -rf '.escapeshellarg($tmpDir));

                return false;
            }

            $this->info('Checking for changes...');
            $statusOutput = [];
            exec('cd '.escapeshellarg($tmpDir).' && git status --porcelain '.escapeshellarg($targetPath).' 2>&1', $statusOutput, $returnCode);
            if ($returnCode !== 0) {
                $this->error('Failed to check repository status: '.implode("\n", $statusOutput));
                exec('rm -rf '.escapeshellarg($tmpDir));

                return false;
            }

            if (empty(array_filter($statusOutput))) {
                $this->info('versions.json is already up to date. No changes to commit.');
                exec('rm -rf '.escapeshellarg($tmpDir));

                return true;
            }

            $envLabel = $nightly ? 'NIGHTLY' : 'PRODUCTION';
            $commitMessage = "Update $envLabel versions.json to $actualVersion - ".date('Y-m-d H:i:s');
            $output = [];
            exec('cd '.escapeshellarg($tmpDir).' && git commit -m '.escapeshellarg($commitMessage).' 2>&1', $output, $returnCode);
            if ($returnCode !== 0) {
                $this->error('Failed to commit changes: '.implode("\n", $output));
                exec('rm -rf '.escapeshellarg($tmpDir));

                return false;
            }

            // Push to remote
            $this->info('Pushing branch to remote...');
            $output = [];
            exec('cd '.escapeshellarg($tmpDir).' && git push origin '.escapeshellarg($branchName).' 2>&1', $output, $returnCode);
            if ($returnCode !== 0) {
                $this->error('Failed to push branch: '.implode("\n", $output));
                exec('rm -rf '.escapeshellarg($tmpDir));

                return false;
            }

            // Create pull request
            $this->info('Creating pull request...');
            $prTitle = "Update $envLabel versions.json to $actualVersion - ".date('Y-m-d H:i:s');
            $prBody = "Automated update of $envLabel versions.json to version $actualVersion";
            $output = [];
            $prCommand = 'gh pr create --repo coollabsio/coolify-cdn --title '.escapeshellarg($prTitle).' --body '.escapeshellarg($prBody).' --base main --head '.escapeshellarg($branchName).' 2>&1';
            exec($prCommand, $output, $returnCode);

            // Clean up
            exec('rm -rf '.escapeshellarg($tmpDir));

            if ($returnCode !== 0) {
                $this->error('Failed to create PR: '.implode("\n", $output));

                return false;
            }

            $this->info('Pull request created successfully!');
            if (! empty($output)) {
                $this->info('PR URL: '.implode("\n", $output));
            }
            $this->info("Version synced: $actualVersion");

            return true;
        } catch (\Throwable $e) {
            $this->error('Error syncing versions.json: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $that = $this;
        $only_template = $this->option('templates');
        $only_version = $this->option('release');
        $only_github_releases = $this->option('github-releases');
        $only_github_versions = $this->option('github-versions');
        $nightly = $this->option('nightly');
        $bunny_cdn = 'https://cdn.coollabs.io';
        $bunny_cdn_path = 'coolify';
        $bunny_cdn_storage_name = 'coolcdn';

        $parent_dir = realpath(dirname(__FILE__).'/../../..');

        $compose_file = 'docker-compose.yml';
        $compose_file_prod = 'docker-compose.prod.yml';
        $install_script = 'install.sh';
        $upgrade_script = 'upgrade.sh';
        $production_env = '.env.production';
        $service_template = config('constants.services.file_name');
        $versions = 'versions.json';

        $compose_file_location = "$parent_dir/$compose_file";
        $compose_file_prod_location = "$parent_dir/$compose_file_prod";
        $install_script_location = "$parent_dir/scripts/install.sh";
        $upgrade_script_location = "$parent_dir/scripts/upgrade.sh";
        $production_env_location = "$parent_dir/.env.production";
        $versions_location = "$parent_dir/$versions";

        PendingRequest::macro('storage', function ($fileName) use ($that) {
            $headers = [
                'AccessKey' => config('constants.bunny.storage_api_key'),
                'Accept' => 'application/json',
                'Content-Type' => 'application/octet-stream',
            ];
            $fileStream = fopen($fileName, 'r');
            $file = fread($fileStream, filesize($fileName));
            $that->info('Uploading: '.$fileName);

            return PendingRequest::baseUrl('https://storage.bunnycdn.com')->withHeaders($headers)->withBody($file)->throw();
        });
        PendingRequest::macro('purge', function ($url) use ($that) {
            $headers = [
                'AccessKey' => config('constants.bunny.api_key'),
                'Accept' => 'application/json',
            ];
            $that->info('Purging: '.$url);

            return PendingRequest::withHeaders($headers)->get('https://api.bunny.net/purge', [
                'url' => $url,
                'async' => false,
            ]);
        });
        try {
            if ($nightly) {
                $bunny_cdn_path = 'coolify-nightly';

                $compose_file_location = "$parent_dir/other/nightly/$compose_file";
                $compose_file_prod_location = "$parent_dir/other/nightly/$compose_file_prod";
                $production_env_location = "$parent_dir/other/nightly/$production_env";
                $upgrade_script_location = "$parent_dir/other/nightly/$upgrade_script";
                $install_script_location = "$parent_dir/other/nightly/$install_script";
                $versions_location = "$parent_dir/other/nightly/$versions";
            }
            if (! $only_template && ! $only_version && ! $only_github_releases && ! $only_github_versions) {
                if ($nightly) {
                    $this->info('About to sync files NIGHTLY (docker-compose.prod.yaml, upgrade.sh, install.sh, etc) to BunnyCDN.');
                } else {
                    $this->info('About to sync files PRODUCTION (docker-compose.yml, docker-compose.prod.yml, upgrade.sh, install.sh, etc) to BunnyCDN.');
                }
                $confirmed = confirm('Are you sure you want to sync?');
                if (! $confirmed) {
                    return;
                }
            }
            if ($only_template) {
                $this->info('About to sync '.config('constants.services.file_name').' to BunnyCDN.');
                $confirmed = confirm('Are you sure you want to sync?');
                if (! $confirmed) {
                    return;
                }
                Http::pool(fn (Pool $pool) => [
                    $pool->storage(fileName: "$parent_dir/templates/$service_template")->put("/$bunny_cdn_storage_name/$bunny_cdn_path/$service_template"),
                    $pool->purge("$bunny_cdn/$bunny_cdn_path/$service_template"),
                ]);
                $this->info('Service template uploaded & purged...');

                return;
            } elseif ($only_version) {
                $this->warn('⚠️  DEPRECATION WARNING: The --release option is deprecated.');
                $this->warn('   Please use --github-versions instead to create a PR to the coolify-cdn repository.');
                $this->warn('   This option will continue to work but may be removed in a future version.');
                $this->newLine();

                if ($nightly) {
                    $this->info('About to sync NIGHLTY versions.json to BunnyCDN.');
                } else {
                    $this->info('About to sync PRODUCTION versions.json to BunnyCDN.');
                }
                $file = file_get_contents($versions_location);
                $json = json_decode($file, true);
                $actual_version = data_get($json, 'coolify.v4.version');

                $confirmed = confirm("Are you sure you want to sync to {$actual_version}?");
                if (! $confirmed) {
                    return;
                }

                // Sync versions.json to BunnyCDN
                Http::pool(fn (Pool $pool) => [
                    $pool->storage(fileName: $versions_location)->put("/$bunny_cdn_storage_name/$bunny_cdn_path/$versions"),
                    $pool->purge("$bunny_cdn/$bunny_cdn_path/$versions"),
                ]);
                $this->info('versions.json uploaded & purged...');

                return;
            } elseif ($only_github_releases) {
                $this->info('About to sync GitHub releases to GitHub repository.');
                $confirmed = confirm('Are you sure you want to sync GitHub releases?');
                if (! $confirmed) {
                    return;
                }

                // Sync releases to GitHub repository
                $this->syncReleasesToGitHubRepo();

                return;
            } elseif ($only_github_versions) {
                $envLabel = $nightly ? 'NIGHTLY' : 'PRODUCTION';
                $file = file_get_contents($versions_location);
                $json = json_decode($file, true);
                $actual_version = data_get($json, 'coolify.v4.version');

                $this->info("About to sync $envLabel versions.json ($actual_version) to GitHub repository.");
                $confirmed = confirm('Are you sure you want to sync versions.json via GitHub PR?');
                if (! $confirmed) {
                    return;
                }

                // Sync versions.json to GitHub repository
                $this->syncVersionsToGitHubRepo($versions_location, $nightly);

                return;
            }

            Http::pool(fn (Pool $pool) => [
                $pool->storage(fileName: "$compose_file_location")->put("/$bunny_cdn_storage_name/$bunny_cdn_path/$compose_file"),
                $pool->storage(fileName: "$compose_file_prod_location")->put("/$bunny_cdn_storage_name/$bunny_cdn_path/$compose_file_prod"),
                $pool->storage(fileName: "$production_env_location")->put("/$bunny_cdn_storage_name/$bunny_cdn_path/$production_env"),
                $pool->storage(fileName: "$upgrade_script_location")->put("/$bunny_cdn_storage_name/$bunny_cdn_path/$upgrade_script"),
                $pool->storage(fileName: "$install_script_location")->put("/$bunny_cdn_storage_name/$bunny_cdn_path/$install_script"),
            ]);
            Http::pool(fn (Pool $pool) => [
                $pool->purge("$bunny_cdn/$bunny_cdn_path/$compose_file"),
                $pool->purge("$bunny_cdn/$bunny_cdn_path/$compose_file_prod"),
                $pool->purge("$bunny_cdn/$bunny_cdn_path/$production_env"),
                $pool->purge("$bunny_cdn/$bunny_cdn_path/$upgrade_script"),
                $pool->purge("$bunny_cdn/$bunny_cdn_path/$install_script"),
            ]);
            $this->info('All files uploaded & purged...');
        } catch (\Throwable $e) {
            $this->error('Error: '.$e->getMessage());
        }
    }
}
