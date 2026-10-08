<?php

namespace App\Livewire\Project\Service;

use App\Models\Service;
use App\Services\ComposeDiff;
use App\Services\TemplateUpdateChecker;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
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

    /** Hunk-selection signature the editor was last seeded from; null until first seed. */
    public ?string $editorSeededSignature = null;

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
        if (! is_string($compose)) {
            return null;
        }

        $decoded = base64_decode($compose, true);

        return $decoded === false ? null : $decoded;
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

    public function getHasHunksProperty(): bool
    {
        return $this->hunks !== [];
    }

    public function getHasSelectedHunksProperty(): bool
    {
        return count(array_filter($this->acceptedHunks)) > 0;
    }

    public function setMode(string $mode): void
    {
        if ($mode === 'edit') {
            $signature = $this->selectionSignature();
            // Re-seed the editable (right) side when first entering edit mode, or
            // when the hunk selection changed since the last seed — otherwise a
            // stale single-hunk merge would hide newly selected changes. Manual
            // edits survive as long as the selection is unchanged.
            if ($this->editorContent === null || $signature !== $this->editorSeededSignature) {
                $anySelected = count(array_filter($this->acceptedHunks)) > 0;
                $this->editorContent = $anySelected
                    ? $this->mergedFromSelection()
                    : ((string) $this->latestCompose !== '' ? (string) $this->latestCompose : (string) $this->service->docker_compose_raw);
                $this->editorSeededSignature = $signature;
            }
            $this->mode = 'edit';

            return;
        }

        $this->mode = 'review';
    }

    private function selectionSignature(): string
    {
        $accepted = array_keys(array_filter($this->acceptedHunks));
        sort($accepted);

        return implode(',', $accepted);
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
        if ($this->latestCompose === null || ! $this->hasSelectedHunks) {
            return;
        }

        $accepted = array_map('intval', array_keys(array_filter($this->acceptedHunks)));
        $merged = ComposeDiff::apply((string) $this->service->docker_compose_raw, $this->latestCompose, $accepted);

        $this->persistCompose($merged);
    }

    public function replaceAll(): void
    {
        if (! $this->hasHunks) {
            return;
        }

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
            $this->editorSeededSignature = null;
            $this->mode = 'review';

            $this->dispatch('success', 'Template changes applied. Redeploy the service for them to take effect.');
            $this->dispatch('refreshEnvs');
            $this->dispatch('refreshServices');
        } catch (\Throwable $e) {
            $this->service->refresh();
            handleError($e, $this);
        }
    }

    public function render(): View
    {
        return view('livewire.project.service.template-update');
    }
}
