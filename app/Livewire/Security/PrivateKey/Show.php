<?php

namespace App\Livewire\Security\PrivateKey;

use App\Models\Application;
use App\Models\GiteaApp;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Support\ValidationPatterns;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    public PrivateKey $private_key;

    // Explicit properties
    public string $name;

    public ?string $description = null;

    /**
     * A new private key that replaces the stored key. The stored key is never sent to the browser.
     */
    public string $newPrivateKey = '';

    public ?string $fingerprint = null;

    public bool $isGitRelated = false;

    public bool $isInUse = false;

    public $public_key = 'Loading...';

    public string $deleteDisabledReason = 'This private key is currently used by a server, application, or Git app and cannot be deleted.';

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'newPrivateKey' => 'nullable|string',
            'isGitRelated' => 'nullable|boolean',
        ];
    }

    protected function messages(): array
    {
        return array_merge(
            ValidationPatterns::combinedMessages(),
            [
                'name.required' => 'The Name field is required.',
                'newPrivateKey.string' => 'The Private Key must be a valid string.',
            ]
        );
    }

    protected $validationAttributes = [
        'name' => 'name',
        'description' => 'description',
        'newPrivateKey' => 'private key',
    ];

    /**
     * Sync data between component properties and model
     *
     * @param  bool  $toModel  If true, sync FROM properties TO model. If false, sync FROM model TO properties.
     */
    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            // Sync TO model (before save)
            $this->private_key->name = $this->name;
            $this->private_key->description = filled($this->description) ? $this->description : null;
            if (filled(trim($this->newPrivateKey))) {
                $this->private_key->private_key = $this->newPrivateKey;
            }
            $this->private_key->is_git_related = $this->isGitRelated;
        } else {
            // Sync FROM model (on load/refresh)
            $this->name = $this->private_key->name;
            $this->description = $this->private_key->description;
            $this->newPrivateKey = '';
            $this->fingerprint = $this->private_key->fingerprint;
            $this->isGitRelated = $this->private_key->is_git_related;
            $this->public_key = $this->private_key->getPublicKey();
        }
    }

    /** @return array<string, string> */
    protected function getListeners(): array
    {
        return ['privateKeyRotated' => '$refresh'];
    }

    public function mount(string $private_key_uuid)
    {
        try {
            $this->private_key = PrivateKey::ownedByCurrentTeam(['name', 'description', 'private_key', 'is_git_related', 'team_id', 'fingerprint'])->whereUuid($private_key_uuid)->firstOrFail();

            // Explicit authorization check - will throw 403 if not authorized
            $this->authorize('view', $this->private_key);

            $this->syncData(false);
            $this->isInUse = $this->private_key->isInUse();
        } catch (AuthorizationException $e) {
            abort(403, 'You do not have permission to view this private key.');
        } catch (\Throwable) {
            abort(404);
        }
    }

    public function delete()
    {
        try {
            $this->authorize('delete', $this->private_key);

            if ($this->private_key->isInUse()) {
                $this->isInUse = true;
                $this->dispatch('error', $this->deleteDisabledReason);

                return;
            }

            $this->private_key->delete();

            $this->dispatch('privateKeyDeleted');
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function changePrivateKey()
    {
        try {
            $this->authorize('update', $this->private_key);

            $this->validate();

            $this->syncData(true);
            $this->private_key->save();
            $this->syncData(false);
            $this->dispatch('success', 'Private key updated.');
            $this->dispatch('privateKeyUpdated');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    /** Reveal attempts per user and minute, so a session cannot guess the password without limit. */
    private const REVEAL_ATTEMPTS_PER_MINUTE = 5;

    /**
     * Return the stored private key once, after a password confirmation.
     * The value goes back to the browser only as the action result, not as component state.
     */
    public function revealPrivateKey(mixed $password): ?string
    {
        try {
            $this->authorize('update', $this->private_key);

            $rateLimitKey = 'private-key-reveal:'.auth()->id();
            if (RateLimiter::tooManyAttempts($rateLimitKey, self::REVEAL_ATTEMPTS_PER_MINUTE)) {
                $this->dispatch('error', 'Too many attempts. Try again in '.RateLimiter::availableIn($rateLimitKey).' seconds.');

                return null;
            }
            RateLimiter::hit($rateLimitKey);

            $auditContext = [
                'team_id' => $this->private_key->team_id,
                'private_key_uuid' => $this->private_key->uuid,
                'private_key_name' => $this->private_key->name,
            ];

            if (! verifyPasswordConfirmation($password, $this)) {
                auditLog('ui.private_key.reveal_failed', $auditContext, 'warning');
                $this->dispatch('error', 'The provided password is incorrect.');

                return null;
            }

            $this->resetErrorBag('password');
            auditLog('ui.private_key.revealed', $auditContext);

            return $this->private_key->private_key;
        } catch (\Throwable $e) {
            handleError($e, $this);

            return null;
        }
    }

    /** Rows shown in the "Used by" list. The counts always cover everything. */
    private const USAGE_LIMIT = 20;

    public string $usageSearch = '';

    /**
     * What of the key's team uses this key: a count per type, and the first rows that match the search, with a link.
     *
     * @return array{counts: array<string, int>, matches: int, rows: Collection<int, array{type: string, name: string, url: ?string}>}
     */
    private function usages(): array
    {
        $keyId = $this->private_key->id;
        $teamId = $this->private_key->team_id;

        $types = [
            'Server' => [
                Server::where('team_id', $teamId)->where('private_key_id', $keyId),
                fn (Server $server) => route('server.show', ['server_uuid' => $server->uuid]),
            ],
            'Application' => [
                Application::where('private_key_id', $keyId)
                    ->whereHas('environment.project', fn ($query) => $query->where('team_id', $teamId))
                    ->with('environment.project'),
                fn (Application $application) => $application->link(),
            ],
            'GitHub App' => [
                GithubApp::where('team_id', $teamId)->where('private_key_id', $keyId),
                fn (GithubApp $app) => route('source.github.show', ['github_app_uuid' => $app->uuid]),
            ],
            'GitLab App' => [
                GitlabApp::where('team_id', $teamId)->where('private_key_id', $keyId),
                fn (GitlabApp $app) => route('source.gitlab.show', ['gitlab_app_uuid' => $app->uuid]),
            ],
            'Gitea App' => [
                GiteaApp::where('team_id', $teamId)->where('private_key_id', $keyId),
                fn (GiteaApp $app) => route('source.gitea.show', ['gitea_app_uuid' => $app->uuid]),
            ],
        ];

        $search = trim($this->usageSearch);
        $pattern = '%'.addcslashes(mb_strtolower($search), '%_\\').'%';
        $counts = [];
        $matches = 0;
        $rows = collect();
        foreach ($types as $type => [$query, $url]) {
            $counts[$type] = (clone $query)->count();
            if ($search !== '') {
                $query->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$pattern]);
            }
            $typeMatches = $search === '' ? $counts[$type] : (clone $query)->count();
            $matches += $typeMatches;
            $missing = self::USAGE_LIMIT - $rows->count();
            if ($typeMatches > 0 && $missing > 0) {
                $rows = $rows->concat($query->orderBy('name')->limit($missing)->get()
                    ->map(fn ($model) => ['type' => $type, 'name' => $model->name, 'url' => $url($model)]));
            }
        }

        return ['counts' => array_filter($counts), 'matches' => $matches, 'rows' => $rows->values()];
    }

    public function render()
    {
        return view('livewire.security.private-key.show', [
            ...$this->usages(),
        ]);
    }
}
