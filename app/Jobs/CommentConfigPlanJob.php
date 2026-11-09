<?php

namespace App\Jobs;

use App\Models\EnvironmentConfigLink;
use App\Models\GithubApp;
use App\Services\ProjectConfig\ApplyContext;
use App\Services\ProjectConfig\ConfigException;
use App\Services\ProjectConfig\ConfigPlan;
use App\Services\ProjectConfig\ConfigPlanner;
use App\Services\ProjectConfig\ConfigRevisions;
use App\Services\ProjectConfig\RepositoryFileReader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Plans coolify.json of a pull request and writes the plan as one comment on it. Nothing is stored or applied.
 * The comment has resource keys and change types only, never values.
 */
class CommentConfigPlanJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public EnvironmentConfigLink $link,
        public int $pullRequestId,
        public string $headSha,
    ) {
        $this->onQueue('high');
    }

    public function handle(RepositoryFileReader $reader, ConfigRevisions $revisions, ConfigPlanner $planner): void
    {
        $source = $this->link->source;
        if (! $source instanceof GithubApp) {
            return;
        }

        try {
            $file = $reader->read($this->link, $this->headSha);
            $body = $file->missing
                ? "coolify.json was not found at `{$this->link->file_path}`."
                : $this->planBody($planner->plan($revisions->resolve($this->link, (string) $file->contents), ApplyContext::for($this->link)));
        } catch (ConfigException $e) {
            $body = "The file is not valid:\n\n".collect($e->errors())->map(fn ($error) => '- `'.($error->path ?: 'file').'`: '.$error->message)->implode("\n");
        } catch (Throwable) {
            $body = 'Coolify could not read coolify.json of this pull request.';
        }

        $this->writeComment($source, $this->header()."\n\n".$body);
    }

    private function header(): string
    {
        $environment = $this->link->environment;

        return "<!-- coolify-config-plan:{$this->link->uuid} -->\n### coolify.json plan for {$environment->project->name} / {$environment->name}\n\nCommit `".substr($this->headSha, 0, 7).'`. Nothing is applied until the pull request is merged.';
    }

    private function planBody(ConfigPlan $plan): string
    {
        $lines = [];
        if ($plan->errors !== []) {
            $lines[] = '**The plan has errors:**';
            foreach ($plan->errors as $error) {
                $lines[] = "- `{$error->path}`";
            }
            $lines[] = 'See the details with `coolify config plan` or on the coolify.json page of the environment.';
            $lines[] = '';
        }
        if ($plan->isEmpty()) {
            $lines[] = 'No changes.';
        } else {
            $lines[] = '| Resource | Change | Setting |';
            $lines[] = '| --- | --- | --- |';
            foreach ($plan->changes as $change) {
                $kind = $change->kind->value.($change->destructive ? ' (destructive)' : '');
                $lines[] = "| `{$change->key}` | {$kind} | ".($change->path === '' ? '-' : "`{$change->path}`").' |';
            }
        }
        if ($plan->isDestructive()) {
            $lines[] = '';
            $lines[] = 'Destructive changes wait for approval in Coolify after the merge.';
        }

        return implode("\n", $lines);
    }

    private function writeComment(GithubApp $source, string $body): void
    {
        $repository = gitRepositorySlug((string) $this->link->repository);
        $marker = "<!-- coolify-config-plan:{$this->link->uuid} -->";
        ['data' => $comments] = githubApi(source: $source, endpoint: "/repos/{$repository}/issues/{$this->pullRequestId}/comments?per_page=100");
        $existing = collect($comments ?? [])->first(fn ($comment) => str_starts_with((string) data_get($comment, 'body'), $marker));

        if ($existing !== null) {
            githubApi(source: $source, endpoint: "/repos/{$repository}/issues/comments/".data_get($existing, 'id'), method: 'patch', data: ['body' => $body]);

            return;
        }
        githubApi(source: $source, endpoint: "/repos/{$repository}/issues/{$this->pullRequestId}/comments", method: 'post', data: ['body' => $body]);
    }
}
