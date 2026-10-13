<?php

namespace App\Livewire;

use App\Models\Project;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Full-screen deploy canvas, opened from an environment's New resource page. The
 * page is a shell: resources/js/canvas/deploy (Svelte) renders the canvas and calls the
 * API controllers at /canvas/api with the browser session, so the API enforces
 * team scope and token permissions.
 */
class DeployCanvas extends Component
{
    /** The team that owned the page at mount; the canvas API calls use it, not the session team. */
    #[Locked]
    public int $teamId;

    public function mount(): void
    {
        $this->teamId = currentTeam()->id;
    }

    public function render(): View
    {
        return view('livewire.deploy-canvas', [
            'serviceTemplates' => $this->serviceTemplates(),
            // Only shows the "New project / environment" rows; the API checks again.
            'canAddProjects' => auth()->user()->can('create', Project::class),
        ])->layout('layouts.app', ['fullBleed' => true]);
    }

    /**
     * One-click service templates for the Service step: the keys that the
     * services API accepts, with the names of the New resource page.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function serviceTemplates(): array
    {
        return get_service_templates()->keys()
            ->map(fn ($key): array => [(string) $key, str((string) $key)->headline()->value()])
            ->sortBy(fn (array $template): string => strtolower($template[1]))
            ->values()
            ->all();
    }
}
