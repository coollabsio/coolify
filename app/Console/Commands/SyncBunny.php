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
    protected $signature = 'sync:bunny {--templates} {--release} {--github-releases} {--nightly}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync files to BunnyCDN';

    /**
     * Fetch GitHub releases and sync to CDN
     */
    private function syncGitHubReleases($parent_dir, $bunny_cdn_storage_name, $bunny_cdn_path, $bunny_cdn)
    {
        $this->info('Fetching releases from GitHub...');
        try {
            $response = Http::timeout(30)
                ->get('https://api.github.com/repos/coollabsio/coolify/releases', [
                    'per_page' => 30,  // Fetch more releases for better changelog
                ]);

            if ($response->successful()) {
                $releases = $response->json();

                // Save releases to a temporary file
                $releases_file = "$parent_dir/releases.json";
                file_put_contents($releases_file, json_encode($releases, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                // Upload to CDN
                Http::pool(fn (Pool $pool) => [
                    $pool->storage(fileName: $releases_file)->put("/$bunny_cdn_storage_name/$bunny_cdn_path/releases.json"),
                    $pool->purge("$bunny_cdn/coolify/releases.json"),
                ]);

                // Clean up temporary file
                unlink($releases_file);

                $this->info('releases.json uploaded & purged...');
                $this->info('Total releases synced: '.count($releases));

                return true;
            } else {
                $this->error('Failed to fetch releases from GitHub: '.$response->status());

                return false;
            }
        } catch (\Throwable $e) {
            $this->error('Error fetching releases: '.$e->getMessage());

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
            if (! $only_template && ! $only_version && ! $only_github_releases) {
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

                // First sync GitHub releases
                $this->info('Syncing GitHub releases first...');
                $this->syncGitHubReleases($parent_dir, $bunny_cdn_storage_name, $bunny_cdn_path, $bunny_cdn);

                // Then sync versions.json
                Http::pool(fn (Pool $pool) => [
                    $pool->storage(fileName: $versions_location)->put("/$bunny_cdn_storage_name/$bunny_cdn_path/$versions"),
                    $pool->purge("$bunny_cdn/$bunny_cdn_path/$versions"),
                ]);
                $this->info('versions.json uploaded & purged...');

                return;
            } elseif ($only_github_releases) {
                $this->info('About to sync GitHub releases to BunnyCDN.');
                $confirmed = confirm('Are you sure you want to sync GitHub releases?');
                if (! $confirmed) {
                    return;
                }

                // Use the reusable function
                $this->syncGitHubReleases($parent_dir, $bunny_cdn_storage_name, $bunny_cdn_path, $bunny_cdn);

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
