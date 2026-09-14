<?php

namespace App\Console\Commands;

use App\Actions\Node\AssignNodeToCluster;
use App\Actions\Node\CreateNodeCluster;
use App\Actions\Node\InstallSentinel;
use App\Actions\Node\ReconcileNodeClusterNetwork;
use App\Actions\Node\ValidateNode;
use App\Actions\Sentinel\PingFluxConnection;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\Team;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class BootstrapDevelopmentQemuNodesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dev:qemu:bootstrap-nodes';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prepare the development QEMU Nodes for workloads and internal DNS';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! isDev()) {
            $this->error('This command may only run in development mode.');

            return self::FAILURE;
        }

        $team = Team::query()->findOrFail(0);
        $user = $team->members()->wherePivot('role', 'owner')->firstOrFail();
        $nodes = Node::query()->whereIn('uuid', [
            'development-qemu-node-worker-a',
            'development-qemu-node-worker-b',
        ])->orderBy('uuid')->get();

        if ($nodes->count() !== 2) {
            throw new RuntimeException('Both development QEMU workers must be seeded before bootstrap.');
        }

        foreach ($nodes as $node) {
            $this->components->task("Installing Sentinel on {$node->name}", fn () => InstallSentinel::run($node));
            if (! ValidateNode::run($node)) {
                throw new RuntimeException("{$node->name} did not pass Podman validation.");
            }
            $this->waitForFlux($node);
        }

        $cluster = NodeCluster::query()
            ->where('team_id', $team->id)
            ->where('name', 'Development QEMU mesh')
            ->first() ?? CreateNodeCluster::run($team, $user, 'Development QEMU mesh', 'Automatically managed development mesh.');

        foreach ($nodes as $node) {
            AssignNodeToCluster::run($cluster, $node);
        }

        $this->components->task('Reconciling WireGuard and internal DNS', fn () => ReconcileNodeClusterNetwork::run($cluster->refresh(), $user));
        $this->info('Development QEMU Nodes are ready.');

        return self::SUCCESS;
    }

    private function waitForFlux(Node $node): void
    {
        for ($attempt = 1; $attempt <= 30; $attempt++) {
            try {
                PingFluxConnection::run($node);

                return;
            } catch (Throwable) {
                if ($attempt === 30) {
                    throw new RuntimeException("{$node->name} did not connect to Flux within 30 seconds.");
                }
                sleep(1);
            }
        }
    }
}
