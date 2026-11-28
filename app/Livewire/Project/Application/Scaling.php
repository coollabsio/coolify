<?php

namespace App\Livewire\Project\Application;

use App\Livewire\Project\Shared\Destination;
use App\Models\Application;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Scaling extends Component
{
    use AuthorizesRequests;

    public Application $application;

    #[Validate(['required', 'integer', 'min:1', 'max:'.Application::MAX_REPLICAS])]
    public int $replicas = 1;

    protected $listeners = ['refresh' => '$refresh'];

    public function mount(): void
    {
        $this->replicas = $this->application->replicas;
    }

    public function submit(): void
    {
        $this->authorize('update', $this->application);
        $this->validate();

        try {
            $reason = $this->application->replicasUnavailableReason();
            if ($reason && $this->replicas > 1) {
                $this->dispatch('error', 'Failed to save replicas.', $reason);

                return;
            }

            $this->application->update(['replicas' => $this->replicas]);
            $this->dispatch('success', 'Replicas updated.', 'Redeploy to apply the change.');
            $this->dispatch('configurationChanged');
            $this->dispatch('refresh')->to(Destination::class);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.application.scaling', [
            'unavailableReason' => $this->application->replicasUnavailableReason(),
        ]);
    }
}
