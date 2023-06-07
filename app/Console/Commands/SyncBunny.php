<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Pool;

class SyncBunny extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:bunny';

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
        $bunny_cdn = "https://cdn.coollabs.io";
        $bunny_cdn_path = "coolify";
        $bunny_cdn_storage_name = "coolcdn";

        $parent_dir = realpath(dirname(__FILE__) . '/../../..');

        $compose_file = "docker-compose.yml";
        $compose_file_prod = "docker-compose.prod.yml";
        $install_script = "install.sh";
        $upgrade_script = "upgrade.sh";
        $docker_install_script = "install-docker.sh";
        $production_env = ".env.production";

        $versions = "versions.json";

        PendingRequest::macro('storage', function ($file) {
            $headers = [
                'AccessKey' => env('BUNNY_STORAGE_API_KEY'),
                'Accept' => 'application/json',
                'Content-Type' => 'application/octet-stream'
            ];
            $fileStream = fopen($file, "r");
            $file = fread($fileStream, filesize($file));
            return PendingRequest::baseUrl('https://storage.bunnycdn.com')->withHeaders($headers)->withBody($file)->throw();
        });
        PendingRequest::macro('purge', function ($url) {
            $headers = [
                'AccessKey' => env('BUNNY_API_KEY'),
                'Accept' => 'application/json',
            ];
            ray('Purging: ' . $url);
            return PendingRequest::withHeaders($headers)->get('https://api.bunny.net/purge', [
                "url" => $url,
                "async" => false
            ]);
        });
        try {
            Http::pool(fn (Pool $pool) => [
                $pool->storage(file: "$parent_dir/$compose_file")->put("/$bunny_cdn_storage_name/$bunny_cdn_path/$compose_file"),
                $pool->storage(file: "$parent_dir/$compose_file_prod")->put("/$bunny_cdn_storage_name/$bunny_cdn_path/$compose_file_prod"),
                $pool->storage(file: "$parent_dir/$production_env")->put("/$bunny_cdn_storage_name/$bunny_cdn_path/$production_env"),
                $pool->storage(file: "$parent_dir/scripts/$upgrade_script")->put("/$bunny_cdn_storage_name/$bunny_cdn_path/$upgrade_script"),
                $pool->storage(file: "$parent_dir/scripts/$install_script")->put("/$bunny_cdn_storage_name/$bunny_cdn_path/$install_script"),
                $pool->storage(file: "$parent_dir/scripts/$docker_install_script")->put("/$bunny_cdn_storage_name/$bunny_cdn_path/$docker_install_script"),
                $pool->storage(file: "$parent_dir/$versions")->put("/$bunny_cdn_storage_name/$bunny_cdn_path/$versions"),
            ]);
            ray("{$bunny_cdn}/{$bunny_cdn_path}");
            Http::pool(fn (Pool $pool) => [
                $pool->purge("$bunny_cdn/$bunny_cdn_path/$compose_file"),
                $pool->purge("$bunny_cdn/$bunny_cdn_path/$compose_file_prod"),
                $pool->purge("$bunny_cdn/$bunny_cdn_path/$production_env"),
                $pool->purge("$bunny_cdn/$bunny_cdn_path/$upgrade_script"),
                $pool->purge("$bunny_cdn/$bunny_cdn_path/$install_script"),
                $pool->purge("$bunny_cdn/$bunny_cdn_path/$docker_install_script"),
                $pool->purge("$bunny_cdn/$bunny_cdn_path/$versions"),
            ]);
            echo "All files uploaded & purged...\n";
        } catch (\Exception $e) {
            echo $e->getMessage();
        }
    }
}
