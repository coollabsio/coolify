<?php

namespace App\Services\ProjectConfig;

use App\Jobs\PlanEnvironmentConfigJob;
use App\Models\Application;
use App\Models\EnvironmentConfigLink;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Routes one git push to the environments that follow its branch. Deployments of managed applications
 * in those environments wait until the file of the push is applied. Bound per request.
 */
class ConfigPushRouter
{
    /** @var array<int, array{link: EnvironmentConfigLink, commit: ?string, held: list<string>}> */
    private array $pending = [];

    /** @var array<int, array{link: EnvironmentConfigLink, commit: ?string}> manual-webhook links that match the push but not its signature */
    private array $candidates = [];

    private bool $flushRegistered = false;

    /**
     * @param  Model|null  $source  The git app that signed the webhook, or null for a manual webhook.
     * @param  Closure(?string): bool  $repositoryMatches  Whether a stored repository is the pushed one.
     * @param  Closure(string): bool|null  $verifySecret  For a manual webhook: checks the signature with a link secret.
     */
    public function beginPush(?Model $source, Closure $repositoryMatches, string $branch, ?string $commit, ?Closure $verifySecret = null): void
    {
        $links = EnvironmentConfigLink::query()
            ->where('mode', EnvironmentConfigLink::MODE_GIT)
            ->where('branch', $branch)
            ->get()
            ->filter(fn (EnvironmentConfigLink $link) => $repositoryMatches($link->repository));

        foreach ($links as $link) {
            if ($source !== null) {
                if ($link->source_type !== $source::class || (int) $link->source_id !== (int) $source->getKey()) {
                    continue;
                }
            } elseif ($verifySecret === null || blank($link->webhook_secret) || ! $verifySecret((string) $link->webhook_secret)) {
                // The push can still be signed with the secret of a managed application of this repository (see hold()).
                $this->candidates[$link->id] = ['link' => $link, 'commit' => $commit];

                continue;
            }

            $this->addPending($link, $commit);
            // One repository webhook signs with one secret, so the managed applications of the repository wait for this push.
            if ($source === null) {
                $managed = Application::query()
                    ->where('environment_id', $link->environment_id)
                    ->whereNotNull('config_key')
                    ->where('git_branch', $branch)
                    ->get()
                    ->filter(fn (Application $application) => $repositoryMatches($application->git_repository));
                foreach ($managed as $application) {
                    $this->pending[$link->id]['held'][] = $application->uuid;
                }
                $link->update(['pending_held_deployments' => array_values(array_unique($this->pending[$link->id]['held']))]);
            }
        }
    }

    private function addPending(EnvironmentConfigLink $link, ?string $commit): void
    {
        $link->update(['pending_commit_sha' => $commit, 'pending_held_deployments' => [], 'pending_pushed_at' => now()]);
        $this->pending[$link->id] = ['link' => $link, 'commit' => $commit, 'held' => []];
        unset($this->candidates[$link->id]);

        if (! $this->flushRegistered) {
            $this->flushRegistered = true;
            app()->terminating(fn () => $this->flush());
        }
    }

    /**
     * Whether the deployment of this application waits for the file of the current push.
     */
    public function hold(Application $application): bool
    {
        if ($application->config_key === null) {
            return false;
        }
        // The webhook verified the signature of this managed application, so the push is authentic for its environment link too.
        foreach ($this->candidates as $entry) {
            if ((int) $entry['link']->environment_id === (int) $application->environment_id && ! $entry['link']->isSuspended()) {
                $this->addPending($entry['link'], $entry['commit']);
            }
        }
        foreach ($this->pending as $id => $entry) {
            if ((int) $entry['link']->environment_id === (int) $application->environment_id) {
                if ($entry['link']->isSuspended()) {
                    return false;
                }
                $this->pending[$id]['held'][] = $application->uuid;
                // Saved at once, so a lost flush can be recovered (project-config:release-stuck).
                $entry['link']->update(['pending_held_deployments' => array_values(array_unique($this->pending[$id]['held']))]);

                return true;
            }
        }

        return false;
    }

    public function hasPending(): bool
    {
        return $this->pending !== [];
    }

    /**
     * Plans each matched link with the applications whose deployments wait for it.
     */
    public function flush(): void
    {
        $pending = $this->pending;
        $this->pending = [];
        foreach ($pending as $entry) {
            self::dispatchPlan($entry['link'], $entry['commit'], array_values(array_unique($entry['held'])));
        }
    }

    /**
     * @param  list<string>  $held
     */
    public static function dispatchPlan(EnvironmentConfigLink $link, ?string $commit, array $held): void
    {
        $link->update(['pending_pushed_at' => null]);
        PlanEnvironmentConfigJob::dispatch($link, 'push', $commit, $held);
    }

    /**
     * Plans pushes whose webhook request ended before its flush ran, for example when the client closed the connection.
     */
    public static function recoverLostPushes(): int
    {
        $links = EnvironmentConfigLink::query()
            ->whereNotNull('pending_pushed_at')
            ->where('pending_pushed_at', '<', now()->subMinutes(2))
            ->get();
        foreach ($links as $link) {
            self::dispatchPlan($link, $link->pending_commit_sha, array_values((array) $link->pending_held_deployments));
        }

        return $links->count();
    }
}
