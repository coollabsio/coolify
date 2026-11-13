<?php

namespace App\Services\ProjectConfig;

use App\Enums\ConfigRevisionStatus;
use App\Jobs\ApplyEnvironmentConfigJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentConfigRevision;
use App\Models\EnvironmentVariable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Applies one revision: plans again, then writes every change in one transaction.
 */
class ConfigApplier
{
    /** @var list<array{0: Model, 1: bool}> */
    private array $restarts = [];

    public function __construct(
        private readonly AdapterRegistry $registry,
        private readonly ConfigPlanner $planner,
        private readonly ConfigRevisions $revisions,
        private readonly HeldDeployments $held,
    ) {}

    public function apply(EnvironmentConfigRevision $revision): void
    {
        if ($revision->link->isSuspended()) {
            return;
        }
        $lock = Cache::lock("project-config:link:{$revision->link_id}", 900);
        if (! $lock->get()) {
            ApplyEnvironmentConfigJob::dispatch($revision)->delay(now()->addSeconds(30));

            return;
        }
        try {
            $next = $this->applyLocked($revision);
        } finally {
            $lock->release();
        }
        if ($next !== null) {
            ApplyEnvironmentConfigJob::dispatch($next);
        }
    }

    /**
     * @return EnvironmentConfigRevision|null A new revision to apply after the lock is released.
     */
    private function applyLocked(EnvironmentConfigRevision $revision): ?EnvironmentConfigRevision
    {
        $newer = EnvironmentConfigRevision::query()
            ->where('link_id', $revision->link_id)
            ->where('id', '>', $revision->id)
            ->whereIn('status', [ConfigRevisionStatus::Applied->value, ConfigRevisionStatus::Applying->value])
            ->exists();
        if ($newer) {
            EnvironmentConfigRevision::query()->whereKey($revision->id)
                ->whereIn('status', [ConfigRevisionStatus::Planned->value, ConfigRevisionStatus::WaitingApproval->value])
                ->update(['status' => ConfigRevisionStatus::Superseded->value]);

            return null;
        }

        $claimed = EnvironmentConfigRevision::query()
            ->whereKey($revision->id)
            ->whereIn('status', [ConfigRevisionStatus::Planned->value, ConfigRevisionStatus::WaitingApproval->value])
            ->update(['status' => ConfigRevisionStatus::Applying->value, 'started_at' => now()]);
        if ($claimed === 0) {
            return null;
        }
        $revision->refresh();
        $link = $revision->link;

        try {
            $this->revisions->supersedeOlder($revision);
            $resolved = $this->revisions->resolve($link, (string) $revision->file_contents);
            $ctx = ApplyContext::for($link);
            $plan = $this->planner->plan($resolved, $ctx);

            if ($plan->hasErrors()) {
                $this->revisions->fail($revision, collect($plan->errors)->map(fn (ConfigError $e) => "{$e->path}: {$e->message}")->implode(' '), [ConfigRevisionStatus::Applying]);

                return null;
            }
            if ($plan->stateHash !== $revision->state_hash) {
                return $this->replan($revision);
            }
            if ($plan->isDestructive() && $revision->approved_at === null) {
                $revision->update(['status' => ConfigRevisionStatus::WaitingApproval, 'started_at' => null]);

                return null;
            }

            $changed = ManagedResources::whileApplying(fn () => DB::transaction(fn () => $this->write($revision, $resolved, $plan, $ctx)));
        } catch (Throwable $e) {
            $this->revisions->fail($revision, $e->getMessage(), [ConfigRevisionStatus::Applying]);

            return null;
        }

        $applied = EnvironmentConfigRevision::query()->whereKey($revision->id)
            ->where('status', ConfigRevisionStatus::Applying->value)
            ->update(['status' => ConfigRevisionStatus::Applied->value, 'applied_at' => now(), 'error' => null]);
        if ($applied > 0) {
            $link->update(['last_applied_revision_id' => $revision->id]);
            auditLog('system.project_config.applied', [
                'team_id' => $link->teamId(),
                'environment_uuid' => $link->environment->uuid,
                'revision_uuid' => $revision->uuid,
                'commit_sha' => $revision->commit_sha,
                'approved_by' => $revision->approved_by,
                'changes' => count($plan->changes),
            ]);
        }

        $skip = [];
        foreach (array_keys($plan->missingRequired) as $key) {
            $model = $this->registry->for($resolved->resources[$key]['type'])->find($link->environment, $key);
            if ($model !== null) {
                $skip[] = $model->uuid;
            }
        }
        if ($applied === 0) {
            // Released during the apply: its held deploys were failed.
            $skip = [...$skip, ...array_column($revision->held_deployments ?? [], 'application_uuid')];
        }
        $this->held->release($revision->fresh(), $changed, $skip);
        foreach ($this->restarts as [$model, $created]) {
            if (! in_array($model->uuid, $skip, true)) {
                $this->held->restart($model, $created);
            }
        }

        return null;
    }

    /**
     * @return list<Application> applications to deploy
     */
    private function write(EnvironmentConfigRevision $revision, ResolvedEnvironment $resolved, ConfigPlan $plan, ApplyContext $ctx): array
    {
        $environment = $ctx->environment;
        $changedKeys = [];
        $writeKeys = [];

        foreach ($plan->changes as $change) {
            match ($change->kind) {
                ChangeKind::Rename => $this->setKey($this->findAny($environment, (string) $change->from), $change->key),
                ChangeKind::Unmanage => $this->setKey($this->findAny($environment, $change->key), null),
                ChangeKind::Import => $this->setKey($this->importTarget($resolved, $change, $ctx), $change->key),
                ChangeKind::Remove => $this->remove($environment, $change, $revision),
                default => null,
            };
            if ($change->kind === ChangeKind::Create || ($change->kind === ChangeKind::Update && $this->affectsRuntime($change->path))) {
                $changedKeys[$change->key] = true;
            }
            if ($change->kind === ChangeKind::Update) {
                $writeKeys[$change->key] = true;
            }
        }

        $deploy = [];
        $this->restarts = [];
        foreach ($resolved->resources as $key => $desired) {
            $adapter = $this->registry->for($desired['type']);
            $model = $adapter->find($environment, $key);
            $created = $model === null;
            if ($created) {
                $model = $adapter->create($key, $desired, $ctx);
            } elseif (isset($writeKeys[$key])) {
                $adapter->update($model, $desired, $ctx);
            } elseif ($this->hasStaleRefs($model, $desired, $ctx)) {
                $adapter->update($model, $desired, $ctx);
                $changedKeys[$key] = true;
            }
            foreach (['internal_url', 'host', 'port'] as $property) {
                $value = $adapter->referenceValue($model, $property);
                if ($value !== null) {
                    $ctx->resolvedRefs["resource.{$key}.{$property}"] = $value;
                }
            }
            if (isset($changedKeys[$key]) && $model instanceof Application) {
                $deploy[] = $model;
            } elseif (isset($changedKeys[$key])) {
                $this->restarts[] = [$model, $created];
            }
        }

        return $deploy;
    }

    /**
     * Whether a stored `resource.<key>.*` variable differs from the value the reference resolves to now.
     */
    private function hasStaleRefs(Model $model, array $desired, ApplyContext $ctx): bool
    {
        foreach ($desired['env'] ?? [] as $name => $entry) {
            foreach ([[$entry, false], [$entry['preview'] ?? null, true]] as [$candidate, $isPreview]) {
                $ref = $candidate['ref'] ?? null;
                if (! is_string($ref) || ! isset($ctx->resolvedRefs[$ref])) {
                    continue;
                }
                $stored = EnvironmentVariable::query()
                    ->where('resourceable_type', $model->getMorphClass())
                    ->where('resourceable_id', $model->getKey())
                    ->where('key', $name)
                    ->where('is_preview', $isPreview)
                    ->first();
                if ($stored?->value !== $ctx->resolvedRefs[$ref]) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Tags, scheduled tasks, backups, the name, and the description do not change the running containers.
     */
    private function affectsRuntime(string $path): bool
    {
        return ! in_array(explode('.', $path)[0], ['tags', 'scheduled_tasks', 'backups', 'name', 'description'], true);
    }

    private function setKey(?Model $model, ?string $key): void
    {
        if ($model === null) {
            return;
        }
        $model->forceFill(['config_key' => $key]);
        $model->saveQuietly();
    }

    private function findAny(Environment $environment, string $key): ?Model
    {
        foreach ($this->registry->all() as $adapter) {
            $model = $adapter->find($environment, $key);
            if ($model !== null) {
                return $model;
            }
        }

        return null;
    }

    private function importTarget(ResolvedEnvironment $resolved, Change $change, ApplyContext $ctx): ?Model
    {
        return $this->registry->for($resolved->resources[$change->key]['type'])->findByUuid($ctx->environment, (string) $change->to);
    }

    private function remove(Environment $environment, Change $change, EnvironmentConfigRevision $revision): void
    {
        $adapter = $this->registry->for((string) $change->from);
        $model = $adapter->find($environment, $change->key);
        if ($model === null) {
            return;
        }
        $this->setKey($model, null);
        // Soft delete first, like the Danger page: the resource leaves lists and deploys at once.
        $model->delete();
        $adapter->delete($model, in_array($change->key, $revision->delete_data ?? [], true));
    }

    private function replan(EnvironmentConfigRevision $revision): ?EnvironmentConfigRevision
    {
        $revision->update([
            'status' => ConfigRevisionStatus::Failed,
            'error' => 'The environment changed after this plan. Coolify planned again.',
        ]);
        $new = $this->revisions->record(
            $revision->link,
            (string) $revision->file_contents,
            'retry',
            $revision->commit_sha,
            $revision->creator,
            $revision->held_deployments ?? [],
        );

        if ($new->status === ConfigRevisionStatus::Failed) {
            $this->revisions->fail($new, (string) $new->error);
        }

        return $new->status === ConfigRevisionStatus::Planned ? $new : null;
    }
}
