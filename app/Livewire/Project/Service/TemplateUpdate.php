<?php

namespace App\Livewire\Project\Service;

use App\Models\Service;
use App\Services\ComposeDiff;
use App\Services\TemplateUpdateChecker;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class TemplateUpdate extends Component
{
    use AuthorizesRequests;

    public Service $service;

    /** @var array<int|string, bool> accepted compose hunks, keyed by hunk index */
    public array $acceptedHunks = [];

    public function mount(Service $service): void
    {
        $this->service = $service;
    }

    public function getTemplateProperty(): ?array
    {
        $templates = get_service_templates();
        $entry = $templates[$this->service->service_type] ?? null;

        return $entry === null ? null : (array) $entry;
    }

    public function getLatestComposeProperty(): ?string
    {
        $compose = data_get($this->template, 'compose');

        return is_string($compose) ? base64_decode($compose) : null;
    }

    public function getLatestUpdatedAtProperty(): ?string
    {
        $raw = data_get($this->template, 'template_last_updated_at');
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw)->format('M j, Y');
        } catch (\Throwable) {
            return null;
        }
    }

    public function getHunksProperty(): array
    {
        if ($this->latestCompose === null) {
            return [];
        }

        return ComposeDiff::hunks((string) $this->service->docker_compose_raw, $this->latestCompose);
    }

    public function getUpdateAvailableProperty(): bool
    {
        return TemplateUpdateChecker::updateAvailable($this->service);
    }

    public function apply(): void
    {
        try {
            $this->authorize('update', $this->service);
            if ($this->latestCompose === null) {
                return;
            }

            $acceptedHunks = array_map('intval', array_keys(array_filter($this->acceptedHunks)));
            $merged = ComposeDiff::apply(
                (string) $this->service->docker_compose_raw,
                $this->latestCompose,
                $acceptedHunks,
            );

            if (! ComposeDiff::isValidYaml($merged)) {
                $this->dispatch('error', 'The selected changes produce invalid YAML. Adjust your selection and try again.');

                return;
            }

            $newHash = TemplateUpdateChecker::currentHash($this->service->service_type);

            // parse() materializes any new env vars from the accepted compose via
            // firstOrCreate, so existing values the user set are never overwritten.
            DB::transaction(function () use ($merged, $newHash): void {
                $this->service->docker_compose_raw = $merged;
                $this->service->template_reference_hash = $newHash;
                $this->service->template_dismissed_hash = null;
                $this->service->save();
                $this->service->parse();
            });

            $this->service->refresh();
            $this->acceptedHunks = [];

            $this->dispatch('success', 'Template changes applied. Redeploy the service for them to take effect.');
            $this->dispatch('refreshEnvs');
            $this->dispatch('refreshServices');
        } catch (\Throwable $e) {
            $this->service->refresh();
            handleError($e, $this);
        }
    }

    public function replaceAll(): void
    {
        $this->acceptedHunks = collect($this->hunks)
            ->pluck('index')
            ->mapWithKeys(fn ($index): array => [$index => true])
            ->all();
        $this->apply();
    }

    public function dismiss(): void
    {
        $this->authorize('update', $this->service);
        $this->service->template_dismissed_hash = TemplateUpdateChecker::currentHash($this->service->service_type);
        $this->service->save();
        $this->dispatch('success', 'Update dismissed. You will be notified when a newer version ships.');
        $this->dispatch('refreshServices');
    }

    public function render()
    {
        return view('livewire.project.service.template-update');
    }
}
