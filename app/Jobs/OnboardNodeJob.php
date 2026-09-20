<?php

namespace App\Jobs;

use App\Actions\Node\InstallSentinel;
use App\Actions\Node\PrepareNodeHost;
use App\Actions\Node\ReconcileNodeClusterNetwork;
use App\Actions\Node\ValidateNode;
use App\Actions\Sentinel\PingFluxConnection;
use App\Models\Node;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class OnboardNodeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1200;

    public function __construct(public int $nodeId, public int $userId) {}

    public function handle(): void
    {
        $node = Node::query()->with('cluster')->findOrFail($this->nodeId);
        $user = User::query()->findOrFail($this->userId);

        try {
            $this->step($node, 'preparing', 'Preparing the server');
            PrepareNodeHost::run($node);

            $this->step($node, 'installing', 'Installing Node components');
            InstallSentinel::run($node);

            $this->step($node, 'connecting', 'Connecting to Coolify');
            if (! ValidateNode::run($node)) {
                throw new RuntimeException($node->fresh()->validation_logs ?: 'Podman validation failed.');
            }
            $this->waitForFlux($node);

            $this->step($node, 'networking', 'Configuring the cluster network');
            ReconcileNodeClusterNetwork::run($node->cluster->refresh(), $user);

            $this->step($node, 'verifying', 'Running final checks');
            PingFluxConnection::run($node);

            $this->step($node, 'ready', 'Node ready', 'ready');
        } catch (Throwable $exception) {
            $this->step($node, 'failed', 'Installation needs attention', 'failed', $this->safeMessage($exception));
            throw $exception;
        }
    }

    private function waitForFlux(Node $node): void
    {
        for ($attempt = 1; $attempt <= 30; $attempt++) {
            try {
                PingFluxConnection::run($node);

                return;
            } catch (Throwable) {
                if ($attempt === 30) {
                    throw new RuntimeException('The Node did not connect to Coolify.');
                }
                sleep(1);
            }
        }
    }

    private function step(Node $node, string $step, string $label, string $status = 'running', ?string $error = null): void
    {
        $node->refresh();
        $node->update(['metadata' => [
            ...($node->metadata ?? []),
            'onboarding' => [
                'status' => $status,
                'step' => $step,
                'label' => $label,
                'error' => $error,
                'updated_at' => now()->toIso8601String(),
            ],
        ]]);
    }

    private function safeMessage(Throwable $exception): string
    {
        return match (true) {
            str_contains($exception->getMessage(), 'Podman') => 'Coolify could not prepare Podman on this server.',
            str_contains($exception->getMessage(), 'connect') => 'The Node could not connect to Coolify.',
            default => 'Coolify could not finish the Node installation. Open the technical details or retry.',
        };
    }
}
