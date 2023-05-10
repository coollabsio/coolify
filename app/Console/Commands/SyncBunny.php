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
        $bunny_cdn = "https://coolify-cdn.b-cdn.net";
        $bunny_cdn_path = "files";
        $bunny_cdn_storage_name = "coolify-cdn";

        $parent_dir = realpath(dirname(__FILE__) . '/../../..');

        $compose_file = "docker-compose.yml";
        $compose_file_prod = "docker-compose.prod.yml";
        $install_script = "install.sh";
        $upgrade_script = "upgrade.sh";
        $docker_install_script = "install-docker.sh";
        $production_env = ".env.production";

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
            return PendingRequest::withHeaders($headers)->post('https://api.bunny.net/purge', [
                "urls" => [$url],
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
            ]);
            Http::pool(fn (Pool $pool) => [
                $pool->purge(url: "$bunny_cdn/$bunny_cdn_path/$compose_file"),
                $pool->purge(url: "$bunny_cdn/$bunny_cdn_path/$compose_file_prod"),
                $pool->purge(url: "$bunny_cdn/$bunny_cdn_path/$production_env"),
                $pool->purge(url: "$bunny_cdn/$bunny_cdn_path/$upgrade_script"),
                $pool->purge(url: "$bunny_cdn/$bunny_cdn_path/$install_script"),
                $pool->purge(url: "$bunny_cdn/$bunny_cdn_path/$docker_install_script"),
            ]);
            echo "All files uploaded & purged...\n";
            return;
            throw new \Exception("Something went wrong.");
        } catch (\Exception $e) {
            echo $e->getMessage();
        }
    }
}
