<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\Docker\DockerProvider;
use Illuminate\Console\Command;

class TesterCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tester';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle(DockerProvider $dockerProvider)
    {
        $server = Server::find(0);
        $network = 'coolify';

        //        $startB = microtime(true);
        //        $resultB = instant_remote_process(["docker network inspect {$network} -f '{{json .Containers}}' "], $server);
        //
        //        $allContainers = format_docker_command_output_to_json($resultB);
        //        $ips = collect([]);
        //        if (count($allContainers) > 0) {
        //            $allContainers = $allContainers[0];
        //            $allContainers = collect($allContainers)->sort()->values();
        //            foreach ($allContainers as $container) {
        //                $containerName = data_get($container, 'Name');
        //                if ($containerName === 'coolify-proxy') {
        //                    continue;
        //                }
        //                if (preg_match('/-(\d{12})/', $containerName)) {
        //                    continue;
        //                }
        //                $containerIp = data_get($container, 'IPv4Address');
        //                if ($containerName && $containerIp) {
        //                    $containerIp = str($containerIp)->before('/');
        //                    $ips->put($containerName, $containerIp->value());
        //                }
        //            }
        //        }
        //        $endB = microtime(true);

        $dockerHelper = $dockerProvider->forServer($server);
        //        $startA = microtime(true);
        $resultA = $dockerHelper->getContainersInNetwork($network);
        //        $endA = microtime(true);

        //        $durationA = $endA - $startA;
        //        $durationB = $endB - $startB;
        //
        //        $this->info("Time taken for resultA: {$durationA} seconds");
        //        $this->info("Time taken for resultB: {$durationB} seconds");

        dd($resultA);
    }
}
