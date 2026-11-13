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

        if ($this->commit !== null && $link->pending_commit_sha !== null && $link->pending_commit_sha !== $this->commit) {
            EnvironmentConfigRevision::create([
                'link_id' => $link->id,
                'trigger' => $this->trigger,
                'commit_sha' => $this->commit,
                'status' => ConfigRevisionStatus::Skipped,
                'error' => 'A newer push replaced this one.',
            ]);
            // The held deploys wait for the file of the newest push instead.
            if ($this->heldApplicationUuids !== []) {
                self::dispatch($link, $this->trigger, $link->pending_commit_sha, $this->heldApplicationUuids);
            }

            return;
        }

        try {
            $file = $reader->read($link, $this->commit);
            $revision = $file->missing
                ? $this->failed($link, $file->commitSha, "coolify.json was not found at {$link->file_path} on {$link->branch}.", $heldDeployments)
                : $revisions->record($link, (string) $file->contents, $this->trigger, $file->commitSha, heldDeployments: $heldDeployments);
        } catch (RepositoryFileException $e) {
            $revision = $this->failed($link, $this->commit, $e->getMessage(), $heldDeployments);
        }

        $revisions->supersedeOlder($revision);
        $links->dispatchWhenPlanned($revision->refresh());
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
