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
            'serviceLogos' => $this->serviceLogos(),
            'resourceLogos' => $this->resourceLogos(),
            // Only shows the "New project / environment" rows; the API checks again.
            'canAddProjects' => auth()->user()->can('create', Project::class),
        ])->layout('layouts.app', ['fullBleed' => true]);
    }

    /**
     * The versioned URLs of the logos in public/svgs/resources for the step icons: name => URL.
     *
     * @return array<string, string>
     */
    private function resourceLogos(): array
    {
        return collect(glob(public_path('svgs/resources/*.svg')) ?: [])
            ->mapWithKeys(fn (string $path): array => [basename($path, '.svg') => resource_logo_url(basename($path, '.svg'))])
            ->all();
    }

    /**
     * Service template logos for the Service step icon, as resource_logo() shows them:
     * template key => [logo URL, CDN fallback URL]. Templates without a logo are left out.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function serviceLogos(): array
    {
        return get_service_templates()
            ->map(fn ($template): mixed => data_get($template, 'logo'))
            ->filter(fn (mixed $logo): bool => is_string($logo) && $logo !== '' && basename($logo) !== 'default.webp')
            ->map(function (string $logo): array {
                $urls = service_logo_urls($logo);

                return [$urls['logo'], $urls['logo_cdn_url']];
            })
            ->all();
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
