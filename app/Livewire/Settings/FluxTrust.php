<?php

namespace App\Livewire\Settings;

use App\Actions\Sentinel\RotateFluxCertificateAuthority;
use App\Jobs\DistributeFluxTrustBundleJob;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Instance-wide Flux CA rotation. Only instance admins (root team owners and admins) can see or change it.
 */
class FluxTrust extends Component
{
    use AuthorizesRequests;

    /** @var array{bundle_version: int, rotation: array<string, mixed>|null, next_step: string|null, nodes: list<array<string, mixed>>, blocking: int} */
    #[Locked]
    public array $status = [];

    public function mount(): void
    {
        abort_unless(isDev() && config('constants.sentinel.host_enabled', false), 404);
        $this->authorize('update', instanceSettings());
        $this->loadStatus();
    }

    public function startRotation(): void
    {
        $this->runStep(fn () => RotateFluxCertificateAuthority::make()->start(auth()->user()), 'Flux CA rotation started. Nodes are receiving the dual-CA trust bundle.');
    }

    public function continueRotation(): void
    {
        $this->runStep(fn () => RotateFluxCertificateAuthority::make()->advance(), 'Flux CA rotation advanced.');
    }

    public function forceContinueRotation(): void
    {
        $this->runStep(fn () => RotateFluxCertificateAuthority::make()->advance(force: true), 'Flux CA rotation advanced. Repair trust on Nodes that had not acknowledged the bundle.');
    }

    public function cancelRotation(): void
    {
        $this->runStep(fn () => RotateFluxCertificateAuthority::make()->cancel(), 'Flux CA rotation cancelled. Nodes are receiving a bundle with the old CA only.');
    }

    public function retryDistribution(): void
    {
        $this->runStep(fn () => DistributeFluxTrustBundleJob::dispatch(), 'Trust bundle delivery queued for connected Nodes.');
    }

    public function refreshStatus(): void
    {
        $this->authorize('update', instanceSettings());
        $this->loadStatus();
    }

    public function render(): View
    {
        return view('livewire.settings.flux-trust');
    }

    private function runStep(callable $step, string $message): void
    {
        $this->authorize('update', instanceSettings());
        try {
            $step();
            $this->dispatch('success', $message);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
        $this->loadStatus();
    }

    private function loadStatus(): void
    {
        $this->status = RotateFluxCertificateAuthority::make()->status();
    }
}
