<?php

namespace App\Livewire\Project\Service;

use App\Models\EnvironmentVariable;
use App\Models\Service;
use App\Services\ComposeDiff;
use App\Services\TemplateEnvDiff;
use App\Services\TemplateUpdateChecker;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class TemplateUpdate extends Component
{
    use AuthorizesRequests;

    public Service $service;

    /** @var array<int, int> accepted compose hunk indexes */
    public array $acceptedHunks = [];

    /** @var array<int, string> env keys the user chose to add/overwrite */
    public array $acceptedEnvKeys = [];

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

            $merged = ComposeDiff::apply(
                (string) $this->service->docker_compose_raw,
                $this->latestCompose,
                $this->acceptedHunks,
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

            $this->dispatch('success', 'Template changes applied. Redeploy the service to make them take effect.');
            $this->dispatch('refreshEnvs');
            $this->dispatch('refreshServices');
        } catch (\Throwable $e) {
            $this->service->refresh();
            handleError($e, $this);
        }
    }

    public function replaceAll(): void
    {
        $this->acceptedHunks = collect($this->hunks)->pluck('index')->all();
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
        foreach ($this->acceptedEnvKeys as $key) {
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
