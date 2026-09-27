<?php

namespace App\Livewire\Project;

use App\Actions\C3\DemoteSite;
use App\Actions\C3\PromoteSite;
use App\Actions\C3\RegenerateStagingCredentials;
use App\Exceptions\C3PromotionBlockedException;
use App\Models\Project;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Connect3 fork: the "Connect3 site" card on the project settings page (PRD 5.3, 5.5).
 */
class C3Settings extends Component
{
    use AuthorizesRequests;

    public Project $project;

    public ?string $client_slug = null;

    public ?string $live_domains = null;

    public ?string $ai_gateway_key_id = null;

    public function mount(): void
    {
        $this->authorize('view', $this->project);
        $this->syncFromModel();
    }

    protected function rules(): array
    {
        return [
            'client_slug' => ['nullable', 'string', 'max:63', 'regex:/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', 'not_regex:/--/', Rule::unique('projects', 'client_slug')->ignore($this->project->id)],
            'live_domains' => ['nullable', 'string', 'max:2000'],
            'ai_gateway_key_id' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function messages(): array
    {
        return [
            'client_slug.regex' => 'Use lowercase letters, digits and single dashes only.',
            'client_slug.not_regex' => 'A double dash is reserved for preview hostnames.',
            'client_slug.unique' => 'Another project already uses this slug.',
        ];
    }

    public function submit(): void
    {
        try {
            $this->authorize('update', $this->project);
            if (is_string($this->client_slug) && str_contains($this->client_slug, '--')) {
                $this->addError('client_slug', 'A double dash is reserved for preview hostnames.');

                return;
            }
            $this->client_slug = c3_normalizeSlug($this->client_slug);
            $this->validate();
            if ($this->project->isLive() && $this->client_slug !== $this->project->client_slug) {
                throw new \RuntimeException('Demote the site before changing its slug.');
            }
            $this->project->client_slug = $this->client_slug;
            $this->project->ai_gateway_key_id = $this->ai_gateway_key_id;
            $this->project->live_domains = c3_parseDomainList($this->live_domains);
            $this->project->save();
            $this->project->refresh();
            $this->syncFromModel();
            $this->dispatch('success', 'Connect3 settings saved.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function promote(): void
    {
        try {
            $this->authorize('update', $this->project);
            $domains = c3_parseDomainList($this->live_domains);
            PromoteSite::run($this->project, $domains);
            $this->project->refresh();
            $this->syncFromModel();
            $this->dispatch('success', 'Site promoted to live: '.implode(', ', $domains));
        } catch (C3PromotionBlockedException $e) {
            $this->dispatch('error', 'Promotion blocked.', $e->getMessage());
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function demote(): void
    {
        try {
            $this->authorize('update', $this->project);
            DemoteSite::run($this->project, 'Demoted from dashboard');
            $this->project->refresh();
            $this->syncFromModel();
            $this->dispatch('success', 'Site demoted to staged. Live routers removed.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function regenerateCredentials(): void
    {
        try {
            $this->authorize('update', $this->project);
            RegenerateStagingCredentials::run($this->project);
            $this->project->refresh();
            $this->dispatch('success', 'Staging password regenerated. Active immediately.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    private function syncFromModel(): void
    {
        $this->client_slug = $this->project->client_slug;
        $this->live_domains = implode(', ', $this->project->liveDomainsList());
        $this->ai_gateway_key_id = $this->project->ai_gateway_key_id;
    }

    public function render()
    {
        return view('livewire.project.c3-settings', [
            'apex' => c3_stagingApex(),
            'stagingUrl' => $this->project->stagingUrl(),
            'previewExample' => $this->project->stagingHost('pr-12'),
            'liveUrls' => $this->project->isLive() ? array_map(fn ($d) => "https://{$d}", $this->project->liveDomainsList()) : [],
        ]);
    }
}
