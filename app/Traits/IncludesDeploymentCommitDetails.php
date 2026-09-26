<?php

namespace App\Traits;

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use Illuminate\Support\Str;

trait IncludesDeploymentCommitDetails
{
    public ?string $commit_sha = null;

    public ?string $commit_message = null;

    public ?string $commit_url = null;

    public ?string $commit_branch = null;

    /**
     * Capture commit details as plain strings so the queued notification serializes
     * the values that existed when the deployment finished. The deployment job passes
     * its in-memory queue record, so no extra query runs per notification.
     */
    protected function resolveDeploymentCommitDetails(Application $application, ?ApplicationDeploymentQueue $deployment, ?ApplicationPreview $preview = null): void
    {
        if (! $deployment) {
            return;
        }

        $commit = trim((string) $deployment->commit);
        if (preg_match('/^[0-9a-f]{7,64}$/i', $commit) === 1) {
            $this->commit_sha = $commit;
            $this->commit_url = $application->gitCommitLink($commit);
        }

        $message = trim(mb_scrub((string) $deployment->commitMessage(), 'UTF-8'));
        if ($message !== '') {
            $this->commit_message = Str::limit($message, 1000);
        }

        if (is_null($preview) && filled($application->git_branch)) {
            $this->commit_branch = $application->git_branch;
        }
    }

    /**
     * @return array{sha: string|null, short_sha: string|null, url: string|null, branch: string|null, message: string|null}|null
     */
    protected function deploymentCommitDetailsForMail(?object $notifiable): ?array
    {
        $enabled = (bool) data_get($notifiable, 'emailNotificationSettings.deployment_commit_details_email_notifications', false);

        if (! $enabled || (is_null($this->commit_sha) && is_null($this->commit_message))) {
            return null;
        }

        return [
            'sha' => $this->commit_sha,
            'short_sha' => $this->commit_sha ? substr($this->commit_sha, 0, 7) : null,
            'url' => $this->commit_url,
            'branch' => $this->commit_branch ? self::escapeMailMarkdown($this->commit_branch) : null,
            'message' => $this->commit_message ? self::formatCommitMessageForMail($this->commit_message) : null,
        ];
    }

    /**
     * Commit messages are user-controlled, so Markdown syntax is escaped before the
     * email layout parses it. Blade escapes HTML separately when the value is echoed.
     */
    private static function escapeMailMarkdown(string $text): string
    {
        return preg_replace('/([\\\\`*_{}\[\]()#+\-.!|~=])/u', '\\\\$1', $text);
    }

    private static function formatCommitMessageForMail(string $message): string
    {
        $paragraphs = preg_split('/\R\s*\R/u', str_replace("\r\n", "\n", $message));

        return collect($paragraphs)
            ->map(fn (string $paragraph): string => collect(preg_split('/\R/u', $paragraph))
                ->map(fn (string $line): string => self::escapeMailMarkdown(trim($line)))
                ->filter(fn (string $line): bool => $line !== '')
                ->implode("\\\n"))
            ->filter(fn (string $paragraph): bool => $paragraph !== '')
            ->implode("\n\n");
    }
}
