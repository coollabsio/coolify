<?php

namespace App\Livewire\Project\Application;

use App\Enums\ApplicationRecommendation;
use App\Models\Application;
use App\Traits\AuditsApplicationSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Recommendations extends Component
{
    use AuditsApplicationSettings, AuthorizesRequests;

    public Application $application;

    public function applyFix(string $recommendation): void
    {
        try {
            $this->authorize('update', $this->application);
            $recommendation = ApplicationRecommendation::from($recommendation);

            $recommendation->applyFix($this->application);
            $this->application->save();
            $this->saveApplicationSettingsWithAudit($this->application);

            $this->dispatch('success', 'Settings saved. Redeploy the application to apply them.');
            $this->refreshConfiguration();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function dismiss(string $recommendation): void
    {
        $this->setDismissed(ApplicationRecommendation::from($recommendation), true);
    }

    public function restore(string $recommendation): void
    {
        $this->setDismissed(ApplicationRecommendation::from($recommendation), false);
    }

    public function render(): View
    {
        return view('livewire.project.application.recommendations', [
            'openRecommendations' => ApplicationRecommendation::openFor($this->application),
            'dismissedRecommendations' => ApplicationRecommendation::dismissedFor($this->application),
        ]);
    }

    private function setDismissed(ApplicationRecommendation $recommendation, bool $dismissed): void
    {
        try {
            $this->authorize('update', $this->application);

            $settings = $this->application->settings;
            $dismissedValues = collect($settings->dismissed_recommendations ?? [])->reject(fn ($value): bool => $value === $recommendation->value);
            $settings->dismissed_recommendations = ($dismissed ? $dismissedValues->push($recommendation->value) : $dismissedValues)->values()->all();
            $this->saveApplicationSettingsWithAudit($this->application);

            $this->refreshConfiguration();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    /**
     * Only the parent page refreshes, so the sidebar count and the build pack dependent menu items update.
     */
    private function refreshConfiguration(): void
    {
        $this->dispatch('refresh')->to(Configuration::class);
    }
}
