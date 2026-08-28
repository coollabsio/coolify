<?php

namespace App\Livewire\Project\Service;

use App\Models\EnvironmentVariable;
use App\Models\Service;
use App\Services\ComposeDiff;
use App\Services\TemplateEnvDiff;
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

    /** @var array<string, bool> accepted env changes, keyed by env key */
    public array $acceptedEnv = [];

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

    public function getEnvDiffProperty(): array
    {
        return $this->template === null
            ? ['new' => [], 'changed' => [], 'removed' => []]
            : TemplateEnvDiff::compute($this->template, $this->service);
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

            $envDiff = $this->envDiff;
            $newHash = TemplateUpdateChecker::currentHash($this->service->service_type);

            DB::transaction(function () use ($merged, $envDiff, $newHash): void {
                $this->service->docker_compose_raw = $merged;
                $this->service->template_reference_hash = $newHash;
                $this->service->template_dismissed_hash = null;
                $this->service->save();

                $this->applyEnvSelections($envDiff);
                $this->service->parse();
            });

            $this->service->refresh();
            $this->acceptedHunks = [];
            $this->acceptedEnv = [];

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

    /**
     * @param  array{new: array<int, array{key:string,value:string}>, changed: array<int, array{key:string,template:string,current:string}>, removed: array<int, array{key:string}>}  $envDiff
     */
    private function applyEnvSelections(array $envDiff): void
    {
        $byKey = collect($envDiff['new'])->concat($envDiff['changed'])->keyBy('key');
        foreach (array_keys(array_filter($this->acceptedEnv)) as $key) {
            $item = $byKey->get($key);
            if ($item === null) {
                continue;
            }
            EnvironmentVariable::updateOrCreate(
                [
                    'key' => $key,
                    'resourceable_id' => $this->service->id,
                    'resourceable_type' => $this->service->getMorphClass(),
                ],
                ['value' => $item['value'] ?? $item['template'] ?? '', 'is_preview' => false],
            );
        }
    }

    public function render()
    {
        return view('livewire.project.service.template-update');
    }
}
