<?php

namespace App\Jobs;

use App\Actions\Node\InstallSentinel;
use App\Actions\Node\PrepareNodeHost;
use App\Actions\Node\ReconcileNodeClusterNetwork;
use App\Actions\Node\ValidateNode;
use App\Actions\Sentinel\PingFluxConnection;
use App\Models\Node;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class OnboardNodeJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1200;

    public int $uniqueFor = 1500;

    public function __construct(public int $nodeId, public int $userId) {}

    public function uniqueId(): string
    {
        return (string) $this->nodeId;
    }

    public function handle(): void
    {
        $node = Node::query()->with('cluster')->findOrFail($this->nodeId);
        $user = User::query()->findOrFail($this->userId);

        try {
            $this->step($node, 'preparing', 'Preparing the server');
            PrepareNodeHost::run($node);

            $this->step($node, 'installing', 'Installing server components');
            InstallSentinel::run($node);

            $this->step($node, 'connecting', 'Connecting to Coolify');
            if (! ValidateNode::run($node)) {
                throw new RuntimeException($node->fresh()->validation_logs ?: 'Podman validation failed.');
            }
            $this->waitForFlux($node);

            $this->step($node, 'networking', 'Configuring the cluster network');
            $cluster = $node->fresh('cluster')->cluster ?? throw new RuntimeException('The server does not belong to a cluster.');
            ReconcileNodeClusterNetwork::run($cluster, $user);

            $this->step($node, 'verifying', 'Running final checks');
            PingFluxConnection::run($node);

            $this->step($node, 'ready', 'Server ready', 'ready');
        } catch (Throwable $exception) {
            $failedStep = (string) data_get($node->fresh()->metadata, 'onboarding.step', 'preparing');
            $this->step(
                $node,
                $failedStep,
                'Installation needs attention',
                'failed',
                $this->safeMessage($exception),
                mb_substr(trim($exception->getMessage()), 0, 4000),
            );
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
                    throw new RuntimeException('The server did not connect to Coolify.');
                }
                sleep(1);
            }
        }
    }

    private function step(
        Node $node,
        string $step,
        string $label,
        string $status = 'running',
        ?string $error = null,
        ?string $technicalError = null,
    ): void {
        $node->refresh();
        // Merged under a row lock: inventory and network writers update other metadata keys meanwhile.
        $node->mergeMetadata(['onboarding' => [
            'status' => $status,
            'step' => $step,
            'label' => $label,
            'error' => $error,
            'technical_error' => $technicalError,
            'updated_at' => now()->toIso8601String(),
        ]]);
    }

    /** A killed or timed-out worker must not leave the onboarding running forever, because only a failed one can be retried. */
    public function failed(?Throwable $exception): void
    {
        $node = Node::query()->find($this->nodeId);
        if ($node === null || ! in_array(data_get($node->metadata, 'onboarding.status'), ['queued', 'running'], true)) {
            return;
        }
        $this->step(
            $node,
            (string) data_get($node->metadata, 'onboarding.step', 'preparing'),
            'Installation needs attention',
            'failed',
            'The installation stopped before completion. Retry the installation.',
            $exception === null ? null : mb_substr(trim($exception->getMessage()), 0, 4000),
        );
    }

    private function safeMessage(Throwable $exception): string
    {
        return match (true) {
            str_contains($exception->getMessage(), 'Podman') => 'Coolify could not prepare Podman on this server.',
            str_contains($exception->getMessage(), 'connect') => 'The server could not connect to Coolify.',
            default => 'Coolify could not finish the server installation. Open the technical details or retry.',
        };
    }
}
