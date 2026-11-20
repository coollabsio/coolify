<?php

namespace App\Jobs;

use App\Enums\ConfigRevisionStatus;
use App\Models\EnvironmentConfigLink;
use App\Models\EnvironmentConfigRevision;
use App\Services\ProjectConfig\ConfigLinks;
use App\Services\ProjectConfig\ConfigRevisions;
use App\Services\ProjectConfig\RepositoryFileException;
use App\Services\ProjectConfig\RepositoryFileReader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Reads coolify.json from the linked branch, plans it, and applies it when the plan is safe.
 */
class PlanEnvironmentConfigJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public bool $deleteWhenMissingModels = true;

    /**
     * @param  list<string>  $heldApplicationUuids
     */
    public function __construct(
        public EnvironmentConfigLink $link,
        public string $trigger,
        public ?string $commit = null,
        public array $heldApplicationUuids = [],
    ) {
        $this->onQueue('high');
    }

    public function handle(RepositoryFileReader $reader, ConfigRevisions $revisions, ConfigLinks $links): void
    {
        $link = $this->link->fresh();
        if ($link === null) {
            return;
        }
        $heldDeployments = array_map(fn (string $uuid) => ['application_uuid' => $uuid, 'commit_sha' => $this->commit], $this->heldApplicationUuids);

        if ($this->replacedByNewerPush($link)) {
            return;
        }

        try {
            $file = $reader->read($link, $this->commit);
        } catch (RepositoryFileException $e) {
            $file = $e;
        }

        // The read can take long. Publish one plan of the link at a time, and only when no newer push came in meanwhile.
        Cache::lock("project-config:plan:{$link->id}", 120)->block(60, function () use ($link, $file, $revisions, $links, $heldDeployments) {
            $link->refresh();
            if ($this->replacedByNewerPush($link)) {
                return;
            }
            $revision = match (true) {
                $file instanceof RepositoryFileException => $this->failed($link, $this->commit, $file->getMessage(), $heldDeployments),
                $file->missing => $this->failed($link, $file->commitSha, "coolify.json was not found at {$link->file_path} on {$link->branch}.", $heldDeployments),
                default => $revisions->record($link, (string) $file->contents, $this->trigger, $file->commitSha, heldDeployments: $heldDeployments),
            };
            $revisions->supersedeOlder($revision);
            $links->dispatchWhenPlanned($revision->refresh());
        });
    }

    /**
     * Records a skipped revision when a newer push of the branch replaced this one. Its held deploys wait for the newest push.
     */
    private function replacedByNewerPush(EnvironmentConfigLink $link): bool
    {
        if ($this->commit === null || $link->pending_commit_sha === null || $link->pending_commit_sha === $this->commit) {
            return false;
        }
        EnvironmentConfigRevision::create([
            'link_id' => $link->id,
            'trigger' => $this->trigger,
            'commit_sha' => $this->commit,
            'status' => ConfigRevisionStatus::Skipped,
            'error' => 'A newer push replaced this one.',
        ]);
        if ($this->heldApplicationUuids !== []) {
            self::dispatch($link, $this->trigger, $link->pending_commit_sha, $this->heldApplicationUuids);
        }

        return true;
    }

    private function failed(EnvironmentConfigLink $link, ?string $commit, string $error, array $heldDeployments): EnvironmentConfigRevision
    {
        return EnvironmentConfigRevision::create([
            'link_id' => $link->id,
            'trigger' => $this->trigger,
            'commit_sha' => $commit,
            'status' => ConfigRevisionStatus::Failed,
            'error' => $error,
            'held_deployments' => $heldDeployments ?: null,
        ]);
    }
}
