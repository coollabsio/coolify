<?php

namespace App\Livewire\Project\Service;

use App\Models\Service;
use App\Services\TemplateUpdateChecker;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class TemplateUpdateBanner extends Component
{
    use AuthorizesRequests;

    public Service $service;

    public string $href;

    public function mount(Service $service, string $href): void
    {
        $this->service = $service;
        $this->href = $href;
    }

    public function getShowBadgeProperty(): bool
    {
        return TemplateUpdateChecker::showBadge($this->service);
    }

    public function dismiss(): void
    {
        try {
            $this->authorize('update', $this->service);
            $this->service->template_dismissed_hash = TemplateUpdateChecker::currentHash($this->service->service_type);
            $this->service->save();
            $this->service->refresh();
            $this->dispatch('success', 'Update dismissed. You will be notified when a newer version ships.');
            $this->dispatch('refreshServices');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render(): View
    {
        return view('livewire.project.service.template-update-banner');
    }
}
