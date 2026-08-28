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

    /** review | edit */
    public string $mode = 'review';

    /** @var array<int|string, bool> accepted compose hunks, keyed by hunk index */
    public array $acceptedHunks = [];

    /** Editable compose in edit mode; seeded lazily. */
    public ?string $editorContent = null;

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

    public function setMode(string $mode): void
    {
        if ($mode === 'edit') {
            if ($this->editorContent === null) {
                // Seed the editable (right) side: carry any hunk selection, otherwise
                // show the full latest template so the diff is immediately meaningful.
                $anySelected = count(array_filter($this->acceptedHunks)) > 0;
                $this->editorContent = $anySelected
                    ? $this->mergedFromSelection()
                    : ((string) $this->latestCompose !== '' ? (string) $this->latestCompose : (string) $this->service->docker_compose_raw);
            }
            $this->mode = 'edit';

            return;
        }

        $this->mode = 'review';
    }

    public function seedFromLatest(): void
    {
        $this->editorContent = (string) $this->latestCompose;
    }

    public function seedFromCurrent(): void
    {
        $this->editorContent = (string) $this->service->docker_compose_raw;
    }

    public function apply(): void
    {
        if ($this->latestCompose === null) {
            return;
        }

        $accepted = array_map('intval', array_keys(array_filter($this->acceptedHunks)));
        $merged = ComposeDiff::apply((string) $this->service->docker_compose_raw, $this->latestCompose, $accepted);

        $this->persistCompose($merged);
    }

    public function replaceAll(): void
    {
        $this->acceptedHunks = collect($this->hunks)
            ->pluck('index')
            ->mapWithKeys(fn ($index): array => [$index => true])
            ->all();
        $this->apply();
    }

    public function applyEditor(): void
    {
        $this->persistCompose((string) $this->editorContent);
    }

    public function dismiss(): void
    {
        $this->authorize('update', $this->service);
        $this->service->template_dismissed_hash = TemplateUpdateChecker::currentHash($this->service->service_type);
        $this->service->save();
        $this->dispatch('success', 'Update dismissed. You will be notified when a newer version ships.');
        $this->dispatch('refreshServices');
    }

    private function mergedFromSelection(): string
    {
        if ($this->latestCompose === null) {
            return (string) $this->service->docker_compose_raw;
        }

        $accepted = array_map('intval', array_keys(array_filter($this->acceptedHunks)));

        return ComposeDiff::apply((string) $this->service->docker_compose_raw, $this->latestCompose, $accepted);
    }

    private function persistCompose(string $compose): void
    {
        try {
            $this->authorize('update', $this->service);

            if (trim($compose) === '' || ! ComposeDiff::isValidYaml($compose)) {
                $this->dispatch('error', 'The compose is not valid YAML. Fix it and try again.');

                return;
            }

            $newHash = TemplateUpdateChecker::currentHash($this->service->service_type);

            // parse() materializes any new env vars via firstOrCreate, so values
            // the user has already set are never overwritten.
            DB::transaction(function () use ($compose, $newHash): void {
                $this->service->docker_compose_raw = $compose;
                $this->service->template_reference_hash = $newHash;
                $this->service->template_dismissed_hash = null;
                $this->service->save();
                $this->service->parse();
            });

            $this->service->refresh();
            $this->acceptedHunks = [];
            $this->editorContent = null;
            $this->mode = 'review';

            $this->dispatch('success', 'Template changes applied. Redeploy the service for them to take effect.');
            $this->dispatch('refreshEnvs');
            $this->dispatch('refreshServices');
        } catch (\Throwable $e) {
            $this->service->refresh();
            handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.service.template-update');
    }
}
