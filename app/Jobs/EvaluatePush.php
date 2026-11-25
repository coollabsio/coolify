<?php

namespace App\Jobs;

use App\Actions\Application\EnsurePullRequestPreview;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Services\DeployTriggers\AffectedRunner;
use App\Services\DeployTriggers\Contract\ApplicationDecision;
use App\Services\DeployTriggers\EvaluationRequestBuilder;
use App\Services\DeployTriggers\EvaluationSlots;
use App\Services\DeployTriggers\GithubFastPath;
use App\Services\DeployTriggers\LegacyWatchPaths;
use App\Services\DeployTriggers\PushContext;
use App\Services\DeployTriggers\TriggerCandidate;
use App\Services\DeployTriggers\TriggerRecorder;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Decides which applications a push affects, then deploys those and records skips for the others.
 */
class EvaluatePush implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $uniqueFor = 300;

    /**
     * @param  list<int>  $applicationIds
     */
    public function __construct(
        public PushContext $context,
        public array $applicationIds,
        public bool $dryRun = false,
        public int $generation = 0,
    ) {
        $this->onQueue(webhooks_queue());
    }

    /**
     * One evaluation per push, provider, and set of applications. A re-dispatch for a full queue is a new generation.
     */
    public function uniqueId(): string
    {
        $ids = $this->applicationIds;
        sort($ids);

        return $this->context->uniqueKey().':'.$this->context->provider.':'.sha1(implode(',', $ids))
            .($this->dryRun ? ':dry' : '').($this->generation > 0 ? ":{$this->generation}" : '');
    }

    /**
     * Releases (busy lane or no free evaluation slot) do not use up the job.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    /**
     * A job that fails or times out deploys every candidate, as an evaluation error does.
     */
    public function failed(Throwable $exception): void
    {
        if ($this->dryRun) {
            return;
        }

        $reason = Str::limit($exception->getMessage(), 300);
        foreach ($this->candidates() as $application) {
            try {
                $candidate = $this->fallbackCandidate($application);
                $this->deploy($candidate, ApplicationDecision::deployBecause($candidate->id, 'evaluation_failed', "The push could not be evaluated ({$reason}), so it deploys."), (string) $this->context->after);
            } catch (Throwable $deployException) {
                report($deployException);
            }
        }
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->context->laneKey()))->releaseAfter(30)->expireAfter($this->timeout)];
    }

    public function handle(): void
    {
        $applications = $this->candidates();
        if ($applications->isEmpty()) {
            return;
        }

        app(EvaluationSlots::class)->run(
            fn () => $this->evaluate($applications),
            fn () => $this->release(15),
        );
    }

    /**
     * @param  Collection<int, Application>  $applications
     */
    private function evaluate(Collection $applications): void
    {
        $after = $this->context->after;
        $lastError = null;
        try {
            ['server' => $server, 'request' => $request, 'access' => $access, 'candidates' => $candidates, 'prechecked' => $decisions]
                = app(EvaluationRequestBuilder::class)->build($applications, $this->context, $this->dryRun);
            $remaining = array_diff_key($candidates, $decisions);
            $fast = app(GithubFastPath::class)->decide($remaining, $this->context);
            $decisions += $fast;
            $remaining = array_diff_key($remaining, $fast);
        } catch (Throwable $exception) {
            $lastError = Str::limit($exception->getMessage(), 300);
            Log::warning('Push evaluation could not start; deploying every candidate.', ['error' => $lastError]);
            $candidates = [];
            $decisions = [];
            foreach ($applications as $application) {
                $candidate = $this->fallbackCandidate($application);
                $candidates[$candidate->id] = $candidate;
                $decisions[$candidate->id] = ApplicationDecision::deployBecause($candidate->id, 'evaluation_failed', "The push could not be evaluated ({$lastError}), so it deploys.");
            }
            $remaining = [];
            $server = $request = $access = null;
        }

        if ($remaining !== []) {
            $runner = app(AffectedRunner::class);
            if ($server !== null && $request !== null && $access !== null && $runner->supports($server)) {
                try {
                    $response = $runner->evaluate($server, $request, $access);
                    $after = $response->after;
                    $decisions += array_intersect_key($response->applications, $remaining);
                } catch (Throwable $exception) {
                    $lastError = Str::limit($exception->getMessage(), 300);
                    Log::warning('Push evaluation failed; deploying every candidate.', ['error' => $lastError, 'applications' => array_keys($remaining)]);
                    foreach (array_keys($remaining) as $id) {
                        $decisions[$id] = ApplicationDecision::deployBecause($id, 'evaluation_failed', "The push could not be evaluated ({$lastError}), so it deploys.");
                    }
                }
            } else {
                foreach ($remaining as $id => $candidate) {
                    $decisions[$id] = $this->withoutHelper($candidate);
                }
            }
        }

        $this->apply($candidates, $decisions, $after, $lastError);
    }

    /**
     * @param  array<string, TriggerCandidate>  $candidates
     * @param  array<string, ApplicationDecision>  $decisions
     */
    private function apply(array $candidates, array $decisions, ?string $after, ?string $lastError): void
    {
        $recorder = app(TriggerRecorder::class);
        $pending = array_keys($candidates);
        foreach ($candidates as $id => $candidate) {
            $decision = $decisions[$id] ?? ApplicationDecision::deployBecause($id, 'error', 'The push could not be evaluated, so it deploys.');
            if ($after !== null) {
                $recorder->storeTriggerSet($candidate->application, $candidate->preview, $decision->triggerSet, $after, $candidate->fingerprint, $decision->reasonCode === 'evaluation_failed' ? $lastError : null);
            }
            array_shift($pending);

            if ($this->dryRun || $after === null) {
                continue;
            }

            if (! $decision->deploys()) {
                $recorder->recordSkip($candidate->application, $candidate->preview, $decision, $after, $candidate->base, $candidate->isStored, $this->context->commitMessage);

                continue;
            }

            if (! $this->deploy($candidate, $decision, $after)) {
                $remainingIds = collect($pending)->prepend($id)->map(fn (string $key): int => $candidates[$key]->application->id)->unique()->values()->all();
                self::dispatch($this->context, $remainingIds, generation: $this->generation + 1)->delay(now()->addSeconds(60));

                return;
            }
        }
    }

    /**
     * @return bool false when the deployment queue of the server is full
     */
    private function deploy(TriggerCandidate $candidate, ApplicationDecision $decision, string $after): bool
    {
        $pullRequest = $this->context->pullRequest;
        if ($pullRequest !== null) {
            EnsurePullRequestPreview::run($candidate->application, $pullRequest, $after);
        }

        $deploymentUuid = new_public_id();
        $result = queue_application_deployment(
            application: $candidate->application,
            deployment_uuid: $deploymentUuid,
            pull_request_id: $pullRequest?->number ?? 0,
            commit: $after,
            is_webhook: true,
            git_type: $pullRequest?->gitType,
        );

        if ($result['status'] === 'queue_full') {
            return false;
        }

        ApplicationDeploymentQueue::query()
            ->where('deployment_uuid', $result['deployment_uuid'] ?? $deploymentUuid)
            ->whereNull('trigger_reason')
            ->first()
            ?->forceFill(['trigger_reason' => $decision->toTriggerReason($after)])
            ->save();

        auditLog('webhook.deployment.queued', [
            'provider' => $this->context->provider,
            'mode' => 'evaluated',
            'application_uuid' => $candidate->application->uuid,
            'application_name' => $candidate->application->name,
            'deployment_uuid' => $deploymentUuid,
            'commit' => $after,
            'reason_code' => $decision->reasonCode,
        ]);

        return true;
    }

    private function fallbackCandidate(Application $application): TriggerCandidate
    {
        $pullRequest = $this->context->pullRequest;
        $preview = $pullRequest === null ? null : ApplicationPreview::query()
            ->where('application_id', $application->id)
            ->where('pull_request_id', $pullRequest->number)
            ->first();

        return new TriggerCandidate(
            id: $pullRequest === null ? $application->uuid : "{$application->uuid}:pr-{$pullRequest->number}",
            application: $application,
            preview: $preview,
            mode: filled($application->watch_paths) ? 'watch_paths' : 'smart',
            base: null,
            isStored: false,
            fingerprint: '',
            scanValid: false,
        );
    }

    private function withoutHelper(TriggerCandidate $candidate): ApplicationDecision
    {
        return $candidate->mode === 'smart'
            ? ApplicationDecision::deployBecause($candidate->id, 'helper_unsupported', 'The helper image of the server cannot evaluate pushes, so it deploys.')
            : LegacyWatchPaths::decide($candidate, $this->context->payloadChangedFiles);
    }

    /**
     * The applications that still want this push: they exist, deploy on push, and follow its branch.
     *
     * @return Collection<int, Application>
     */
    private function candidates(): Collection
    {
        $branch = $this->context->pullRequest?->targetBranch ?? Str::after($this->context->ref, 'refs/heads/');

        return Application::query()
            ->whereIn('id', $this->applicationIds)
            ->orderBy('id')
            ->get()
            ->filter(fn (Application $application): bool => $application->git_branch === $branch
                && ($this->dryRun || ($this->context->pullRequest !== null ? $application->isPRDeployable() : $application->isDeployable())))
            // A redelivered webhook of a push that was already evaluated changes nothing.
            ->reject(fn (Application $application): bool => ! $this->dryRun && $this->context->pullRequest === null
                && $this->context->after !== null
                && data_get($application->trigger_set, 'evaluated_commit') === $this->context->after
                && data_get($application->trigger_set, 'last_error') === null)
            ->values();
    }
}
