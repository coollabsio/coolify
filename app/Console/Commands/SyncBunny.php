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
    protected $signature = 'sync:bunny {--templates} {--release} {--nightly}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync files to BunnyCDN';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $that = $this;
        $only_template = $this->option('templates');
        $only_version = $this->option('release');
        $nightly = $this->option('nightly');
        $bunny_cdn = 'https://cdn.coollabs.io';
        $bunny_cdn_path = 'coolify';
        $bunny_cdn_storage_name = 'coolcdn';

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
                $upgrade_postgres_script_location = "$parent_dir/other/nightly/$upgrade_postgres_script";
                $install_script_location = "$parent_dir/other/nightly/$install_script";
                $versions_location = "$parent_dir/other/nightly/$versions";
            }
            if (! $only_template && ! $only_version) {
                $envLabel = $nightly ? 'NIGHTLY' : 'PRODUCTION';
                $this->info("About to sync $envLabel files to BunnyCDN.");
                $this->newLine();

                // BunnyCDN file mapping (local file => CDN URL path)
                $bunnyFileMapping = [
                    $compose_file_location => "$bunny_cdn/$bunny_cdn_path/$compose_file",
                    $compose_file_prod_location => "$bunny_cdn/$bunny_cdn_path/$compose_file_prod",
                    $production_env_location => "$bunny_cdn/$bunny_cdn_path/$production_env",
                    $upgrade_script_location => "$bunny_cdn/$bunny_cdn_path/$upgrade_script",
                    $upgrade_postgres_script_location => "$bunny_cdn/$bunny_cdn_path/$upgrade_postgres_script",
                    $install_script_location => "$bunny_cdn/$bunny_cdn_path/$install_script",
                ];

                $diffTmpDir = sys_get_temp_dir().'/coolify-cdn-diff-'.time();
                @mkdir($diffTmpDir, 0755, true);
                $hasChanges = false;

                // Diff against BunnyCDN
                $this->info('Fetching files from BunnyCDN to compare...');
                foreach ($bunnyFileMapping as $localFile => $cdnUrl) {
                    if (! file_exists($localFile)) {
                        $this->warn('Local file not found: '.$localFile);

                        continue;
                    }

                    $fileName = basename($cdnUrl);
                    $remoteTmp = "$diffTmpDir/bunny-$fileName";

                    try {
                        $response = Http::timeout(10)->get($cdnUrl);
                        if ($response->successful()) {
                            file_put_contents($remoteTmp, $response->body());
                            $diffOutput = [];
                            exec('diff -u '.escapeshellarg($remoteTmp).' '.escapeshellarg($localFile).' 2>&1', $diffOutput, $diffCode);
                            if ($diffCode !== 0) {
                                $hasChanges = true;
                                $this->newLine();
                                $this->info("--- BunnyCDN: $bunny_cdn_path/$fileName");
                                $this->info("+++ Local: $fileName");
                                foreach ($diffOutput as $line) {
                                    if (str_starts_with($line, '---') || str_starts_with($line, '+++')) {
                                        continue;
                                    }
                                    $this->line($line);
                                }
                            }
                        } else {
                            $this->info("NEW on BunnyCDN: $bunny_cdn_path/$fileName (HTTP {$response->status()})");
                            $hasChanges = true;
                        }
                    } catch (\Throwable $e) {
                        $this->warn("Could not fetch $cdnUrl: {$e->getMessage()}");
                    }
                }

                exec('rm -rf '.escapeshellarg($diffTmpDir));

                if (! $hasChanges) {
                    $this->newLine();
                    $this->info('No differences found. All files are already up to date.');

                    return;
                }

                $this->newLine();

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
                    $this->info('About to sync NIGHTLY versions.json to BunnyCDN.');
                } else {
                    $this->info('About to sync PRODUCTION versions.json to BunnyCDN.');
                }
                $file = file_get_contents($versions_location);
                $json = json_decode($file, true);
                $actual_version = data_get($json, 'coolify.v4.version');

                $this->info("Version: {$actual_version}");
                $this->info('This will:');
                $this->info('  1. Sync versions.json to BunnyCDN');
                $this->newLine();

                $confirmed = confirm('Are you sure you want to proceed?');
                if (! $confirmed) {
                    return;
                }

                $this->info('Syncing versions.json to BunnyCDN...');
                Http::pool(fn (Pool $pool) => [
                    $pool->storage(fileName: $versions_location)->put("/$bunny_cdn_storage_name/$bunny_cdn_path/$versions"),
                    $pool->purge("$bunny_cdn/$bunny_cdn_path/$versions"),
                ]);
                $this->info('✓ versions.json uploaded & purged to BunnyCDN');
                $this->newLine();

                $this->info('=== Summary ===');
                $this->info('BunnyCDN sync: ✓ Complete');

                return;
            }

            Http::pool(fn (Pool $pool) => [
                $pool->storage(fileName: "$compose_file_location")->put("/$bunny_cdn_storage_name/$bunny_cdn_path/$compose_file"),
                $pool->storage(fileName: "$compose_file_prod_location")->put("/$bunny_cdn_storage_name/$bunny_cdn_path/$compose_file_prod"),
                $pool->storage(fileName: "$production_env_location")->put("/$bunny_cdn_storage_name/$bunny_cdn_path/$production_env"),
                $pool->storage(fileName: "$upgrade_script_location")->put("/$bunny_cdn_storage_name/$bunny_cdn_path/$upgrade_script"),
                $pool->storage(fileName: "$upgrade_postgres_script_location")->put("/$bunny_cdn_storage_name/$bunny_cdn_path/$upgrade_postgres_script"),
                $pool->storage(fileName: "$install_script_location")->put("/$bunny_cdn_storage_name/$bunny_cdn_path/$install_script"),
            ]);
            Http::pool(fn (Pool $pool) => [
                $pool->purge("$bunny_cdn/$bunny_cdn_path/$compose_file"),
                $pool->purge("$bunny_cdn/$bunny_cdn_path/$compose_file_prod"),
                $pool->purge("$bunny_cdn/$bunny_cdn_path/$production_env"),
                $pool->purge("$bunny_cdn/$bunny_cdn_path/$upgrade_script"),
                $pool->purge("$bunny_cdn/$bunny_cdn_path/$upgrade_postgres_script"),
                $pool->purge("$bunny_cdn/$bunny_cdn_path/$install_script"),
            ]);
            $this->info('All files uploaded & purged to BunnyCDN.');
            $this->newLine();

            $this->info('=== Summary ===');
            $this->info('BunnyCDN sync: Complete');
        } catch (\Throwable $e) {
            $this->error('Error: '.$e->getMessage());
        }
    }
}
