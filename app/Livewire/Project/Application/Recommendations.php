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
            $this->reloadGeneralPage();
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
     * Only the parent page refreshes, so the recommendation dot on the General menu item updates.
     */
    private function refreshConfiguration(): void
    {
        $this->dispatch('refresh')->to(Configuration::class);
    }

    /**
     * A fix can change a value that the General form holds, such as the build pack. A reload loads the form again,
     * so a later save of the form does not write the old value back.
     */
    private function reloadGeneralPage(): void
    {
        $this->redirectRoute('project.application.configuration', [
            'project_uuid' => $this->application->environment->project->uuid,
            'environment_uuid' => $this->application->environment->uuid,
            'application_uuid' => $this->application->uuid,
        ], navigate: true);
    }
}
