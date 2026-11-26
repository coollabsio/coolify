<?php

namespace App\Livewire\Project\Application;

use App\Jobs\EvaluatePush;
use App\Models\Application;
use App\Models\GiteaApp;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\InstanceSettings;
use App\Services\DeployTriggers\Contract\Sha;
use App\Services\DeployTriggers\PushContext;
use App\Traits\AuditsApplicationSettings;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class DeployTriggers extends Component
{
    use AuditsApplicationSettings, AuthorizesRequests;

    public Application $application;

    /** Which pushes deploy: `all`, `smart`, or `watch_paths`. Saved watch paths win over smart deploy triggers. */
    public string $triggerMode = 'smart';

    /** The watch paths as typed. Switching to another mode clears the saved ones but keeps them here, so switching back restores them. */
    public ?string $watchPaths = null;

    /** Polls for the result of Evaluate now until the stored set changes or two minutes pass. */
    public bool $isEvaluating = false;

    public ?string $evaluatingSince = null;

    public ?string $evaluatedAtBefore = null;

    private const TRIGGER_MODES = ['all', 'smart', 'watch_paths'];

    public function mount(): void
    {
        $this->watchPaths = $this->application->watch_paths;
        $this->triggerMode = $this->savedTriggerMode();
    }

    public function setTriggerMode(): void
    {
        try {
            $this->authorize('update', $this->application);
            if (! in_array($this->triggerMode, self::TRIGGER_MODES, true)) {
                throw new \InvalidArgumentException('Choose which pushes deploy.');
            }
            if ($this->triggerMode === 'smart' && ! self::isSmartAllowedOnInstance()) {
                throw new \InvalidArgumentException('Smart deploy triggers are turned off for this instance.');
            }

            if ($this->triggerMode === 'watch_paths') {
                if (filled($this->watchPaths) && blank($this->application->watch_paths)) {
                    $this->storeWatchPaths();
                }

                return;
            }

            $this->application->watch_paths = null;
            $this->application->save();
            $this->application->settings->is_smart_triggers_enabled = $this->triggerMode === 'smart';
            $this->saveApplicationSettingsWithAudit($this->application);
            unset($this->triggerSummary);
            $this->dispatch('success', $this->triggerMode === 'smart' ? 'Smart deploy triggers decide which pushes deploy.' : 'Every push deploys.');
        } catch (\Throwable $e) {
            $this->application->refresh();
            $this->triggerMode = $this->savedTriggerMode();
            handleError($e, $this);
        }
    }

    public function saveWatchPaths(): void
    {
        try {
            $this->authorize('update', $this->application);
            $this->validate(['watchPaths' => 'nullable|string']);
            $this->storeWatchPaths();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    private function storeWatchPaths(): void
    {
        $this->application->watch_paths = $this->watchPaths;
        $this->application->save();
        $this->watchPaths = $this->application->watch_paths;
        unset($this->triggerSummary);
        if (filled($this->application->watch_paths)) {
            $this->triggerMode = 'watch_paths';
            $this->dispatch('success', 'Watch paths decide which pushes deploy.');

            return;
        }
        $this->triggerMode = $this->savedTriggerMode();
        $this->dispatch('success', 'Watch paths cleared.');
    }

    private function savedTriggerMode(): string
    {
        return match (true) {
            filled($this->application->watch_paths) => 'watch_paths',
            (bool) ($this->application->settings?->is_smart_triggers_enabled ?? true) => 'smart',
            default => 'all',
        };
    }

    public static function isSmartAllowedOnInstance(): bool
    {
        return (bool) (InstanceSettings::query()->whereKey(0)->value('is_smart_triggers_enabled') ?? true);
    }

    /**
     * Resolves the trigger paths at the deployed commit without deploying, so they show before the next push.
     */
    public function evaluateNow(): void
    {
        try {
            $this->authorize('update', $this->application);
            $key = 'evaluate-now:'.$this->application->uuid;
            if (RateLimiter::tooManyAttempts($key, 3)) {
                $this->dispatch('error', 'Please wait a minute before evaluating again.');

                return;
            }
            $commit = $this->evaluationCommit();
            if ($commit === null) {
                $this->dispatch('error', 'Deploy the application once, then evaluate its trigger paths.');

                return;
            }
            RateLimiter::hit($key, 60);

            EvaluatePush::dispatch(new PushContext(
                provider: match ($this->application->source_type) {
                    GithubApp::class => 'github',
                    GitlabApp::class => 'gitlab',
                    GiteaApp::class => 'gitea',
                    default => 'github_manual',
                },
                teamId: (int) $this->application->team()->id,
                repositoryUrl: (string) $this->application->git_repository,
                ref: "refs/heads/{$this->application->git_branch}",
                after: $commit,
                githubAppId: $this->application->source_type === GithubApp::class ? $this->application->source_id : null,
            ), [$this->application->id], dryRun: true);
            $this->isEvaluating = true;
            $this->evaluatingSince = now()->toIso8601String();
            $this->evaluatedAtBefore = data_get($this->application->trigger_set, 'evaluated_at');
            $this->dispatch('success', 'Evaluating the branch. The trigger paths update in a moment.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function checkEvaluation(): void
    {
        $this->refreshApplication();
        $finished = data_get($this->application->trigger_set, 'evaluated_at') !== $this->evaluatedAtBefore;
        if ($finished || $this->evaluatingSince === null || now()->subMinutes(2)->greaterThan($this->evaluatingSince)) {
            $this->isEvaluating = false;
        }
    }

    #[On('configurationChanged')]
    public function refreshApplication(): void
    {
        $this->application->refresh();
        unset($this->triggerSummary);
    }

    /**
     * The deployed state of the application: its base commit, else its last successful deployment.
     */
    private function evaluationCommit(): ?string
    {
        $commit = $this->application->trigger_base_commit
            ?? $this->application->get_last_successful_deployment(['commit'])?->commit;

        return Sha::isValid($commit) ? $commit : null;
    }

    /**
     * @return array{state: 'none'|'not_narrowable'|'watch_paths'|'disabled_instance'|'disabled'|'evaluated', display: list<array{path: string, source: ?string, excluded: bool}>, groups: list<array{label: string, paths: list<string>}>, resolvers: list<string>, anchor: ?string, notes: list<string>, lastError: ?string, lastErrorFromEvaluateNow: bool, evaluatedCommit: ?string}
     */
    #[Computed]
    public function triggerSummary(): array
    {
        $set = $this->application->trigger_set ?? [];
        $summary = [
            'display' => array_values(array_map(fn (string $line): array => self::displayLine($line, $set['anchor']['dir'] ?? null), array_filter($set['display'] ?? [], 'is_string'))),
            'resolvers' => array_values(array_filter($set['resolvers'] ?? [], 'is_string')),
            'anchor' => is_string($set['anchor']['package'] ?? null) ? $set['anchor']['package'] : null,
            'notes' => array_values(array_filter(array_map(fn (mixed $note): ?string => is_array($note) ? ($note['reason'] ?? null) : null, $set['widened'] ?? []))),
            'lastError' => is_string($set['last_error'] ?? null) ? $set['last_error'] : null,
            'lastErrorFromEvaluateNow' => (bool) ($set['last_error_dry_run'] ?? false),
            'evaluatedCommit' => is_string($set['evaluated_commit'] ?? null) ? $set['evaluated_commit'] : null,
        ];

        $summary['groups'] = self::pathGroups($summary['display']);

        $state = match (true) {
            filled($this->application->watch_paths) => 'watch_paths',
            ! self::isSmartAllowedOnInstance() => 'disabled_instance',
            ! (bool) ($this->application->settings?->is_smart_triggers_enabled ?? true) => 'disabled',
            ! $this->application->isNarrowable() => 'not_narrowable',
            $summary['evaluatedCommit'] === null => 'none',
            default => 'evaluated',
        };

        return ['state' => $state, ...$summary];
    }

    /** Group headings in display order, keyed by the source of a path. */
    private const GROUP_LABELS = [
        'Application package' => 'Application',
        'Workspace package' => 'Workspace packages',
        'Workspace file' => 'Workspace files',
        'Build context' => 'Build context',
        'Dockerfile' => 'Dockerfile',
        'Compose file' => 'Compose files',
        'Symlink target' => 'Symlink targets',
    ];

    /**
     * Groups the trigger paths by where they come from, with excluded paths last.
     *
     * @param  list<array{path: string, source: ?string, excluded: bool}>  $lines
     * @return list<array{label: string, paths: list<string>}>
     */
    private static function pathGroups(array $lines): array
    {
        $groups = [];
        foreach ($lines as $line) {
            $label = $line['excluded'] ? 'Ignored' : (self::GROUP_LABELS[$line['source']] ?? $line['source'] ?? 'Paths');
            $groups[$label][] = $line['path'];
        }
        $order = [...array_values(self::GROUP_LABELS), 'Paths', 'Ignored'];
        $rank = fn (string $label): int => ($index = array_search($label, $order, true)) === false ? count(self::GROUP_LABELS) : $index;
        uksort($groups, fn (string $a, string $b): int => $rank($a) <=> $rank($b));

        return array_map(fn (string $label, array $paths): array => ['label' => $label, 'paths' => $paths], array_keys($groups), array_values($groups));
    }

    /**
     * Splits a stored line such as `workspace: packages/ui/**` into the path and where it comes from.
     *
     * @return array{path: string, source: ?string, excluded: bool}
     */
    private static function displayLine(string $line, mixed $anchorDirectory): array
    {
        [$limit, $path] = str_contains($line, ': ') ? explode(': ', $line, 2) : [null, $line];
        $excluded = str_starts_with($path, '!');
        $path = ltrim($path, '!');

        return [
            'path' => $path,
            'source' => match (true) {
                $limit === 'workspace' && is_string($anchorDirectory) && $path === $anchorDirectory.'/**' => 'Application package',
                $limit === 'workspace' && str_ends_with($path, '/**') && ! str_starts_with($path, '*') => 'Workspace package',
                $limit === 'workspace' => 'Workspace file',
                default => match ($limit) {
                    'build_context' => 'Build context',
                    'dockerfile' => 'Dockerfile',
                    'compose' => 'Compose file',
                    'symlink' => 'Symlink target',
                    null => null,
                    default => str($limit)->replace('_', ' ')->ucfirst()->toString(),
                },
            },
            'excluded' => $excluded,
        ];
    }

    public function render()
    {
        return view('livewire.project.application.deploy-triggers');
    }
}
