<?php

namespace App\Actions\Application;

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Services\DeployTriggers\PullRequestContext;
use Lorisleiva\Actions\Concerns\AsAction;

class EnsurePullRequestPreview
{
    use AsAction;

    /**
     * The preview of a pull request, created with its domains when it does not exist yet.
     */
    public function handle(Application $application, PullRequestContext $pullRequest, ?string $commit): ApplicationPreview
    {
        $preview = ApplicationPreview::query()
            ->where('application_id', $application->id)
            ->where('pull_request_id', $pullRequest->number)
            ->first();

        if (! $preview) {
            $attributes = [
                'git_type' => $pullRequest->gitType,
                'application_id' => $application->id,
                'pull_request_id' => $pullRequest->number,
                'pull_request_html_url' => $pullRequest->htmlUrl ?? '',
            ];
            if ($application->build_pack === 'dockercompose') {
                $preview = ApplicationPreview::create($attributes + ['docker_compose_domains' => $application->docker_compose_domains]);
                $preview->generate_preview_fqdn_compose();
            } else {
                $preview = ApplicationPreview::create($attributes);
                $preview->generate_preview_fqdn();
            }
        }

        $preview->markForkPullRequest($pullRequest->isFork, $commit);

        return $preview;
    }
}
