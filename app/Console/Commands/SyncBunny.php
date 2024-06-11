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
    protected $signature = 'sync:bunny {--templates} {--release}';

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
        $bunny_cdn = 'https://cdn.coollabs.io';
        $bunny_cdn_path = 'coolify';
        $bunny_cdn_storage_name = 'coolcdn';

        $parent_dir = realpath(dirname(__FILE__).'/../../..');

        $compose_file = 'docker-compose.yml';
        $compose_file_prod = 'docker-compose.prod.yml';
        $install_script = 'install.sh';
        $upgrade_script = 'upgrade.sh';
        $production_env = '.env.production';
        $service_template = 'service-templates.json';

        $versions = 'versions.json';

        PendingRequest::macro('storage', function ($fileName) use ($that) {
            $headers = [
                'AccessKey' => env('BUNNY_STORAGE_API_KEY'),
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
                'AccessKey' => env('BUNNY_API_KEY'),
                'Accept' => 'application/json',
            ];
            $that->info('Purging: '.$url);

            return PendingRequest::withHeaders($headers)->get('https://api.bunny.net/purge', [
                'url' => $url,
                'async' => false,
            ]);
        });
        try {
            if (! $only_template && ! $only_version) {
                $this->info('About to sync files (docker-compose.prod.yaml, upgrade.sh, install.sh, etc) to BunnyCDN.');
            }
            if ($only_template) {
                $this->info('About to sync service-templates.json to BunnyCDN.');
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
                $this->info('About to sync versions.json to BunnyCDN.');
                $file = file_get_contents("$parent_dir/$versions");
                $json = json_decode($file, true);
                $actual_version = data_get($json, 'coolify.v4.version');

                $confirmed = confirm("Are you sure you want to sync to {$actual_version}?");
                if (! $confirmed) {
                    return;
                }
                Http::pool(fn (Pool $pool) => [
                    $pool->storage(fileName: "$parent_dir/$versions")->put("/$bunny_cdn_storage_name/$bunny_cdn_path/$versions"),
                    $pool->purge("$bunny_cdn/$bunny_cdn_path/$versions"),
                ]);
                $this->info('versions.json uploaded & purged...');

                return;
            }

            Http::pool(fn (Pool $pool) => [
                $pool->storage(fileName: "$parent_dir/$compose_file")->put("/$bunny_cdn_storage_name/$bunny_cdn_path/$compose_file"),
                $pool->storage(fileName: "$parent_dir/$compose_file_prod")->put("/$bunny_cdn_storage_name/$bunny_cdn_path/$compose_file_prod"),
                $pool->storage(fileName: "$parent_dir/$production_env")->put("/$bunny_cdn_storage_name/$bunny_cdn_path/$production_env"),
                $pool->storage(fileName: "$parent_dir/scripts/$upgrade_script")->put("/$bunny_cdn_storage_name/$bunny_cdn_path/$upgrade_script"),
                $pool->storage(fileName: "$parent_dir/scripts/$install_script")->put("/$bunny_cdn_storage_name/$bunny_cdn_path/$install_script"),
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
