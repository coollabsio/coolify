<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

class ExplainFailedDeploy extends Prompt
{
    protected string $name = 'explain_failed_deploy';

    protected string $description = 'Guided workflow to explain a failed Coolify deployment using list/get deployment tools and optional log summary.';

    public function handle(Request $request): Response
    {
        $deploymentUuid = $request->get('deployment_uuid');
        $applicationUuid = $request->get('application_uuid');

        $deploymentUuid = is_string($deploymentUuid) && $deploymentUuid !== '' ? $deploymentUuid : null;
        $applicationUuid = is_string($applicationUuid) && $applicationUuid !== '' ? $applicationUuid : null;

        $steps = [];
        if ($deploymentUuid) {
            $steps[] = "Call `get_deployment` with uuid=`{$deploymentUuid}` and include_log_summary=true, log_lines=60.";
            $steps[] = 'Note status, commit, finished_at, and log_summary text (already redacted/truncated).';
            $steps[] = 'If application_uuid is present on the result, call `get_application` for context (branch, build pack, fqdn).';
            $steps[] = 'Optionally `list_deployments` with that application_uuid to compare with the previous successful deploy.';
        } elseif ($applicationUuid) {
            $steps[] = "Call `list_deployments` with application_uuid=`{$applicationUuid}` and review the most recent failed/cancelled items.";
            $steps[] = 'Pick the failed deployment_uuid and call `get_deployment` with include_log_summary=true.';
            $steps[] = "Call `get_application` with uuid=`{$applicationUuid}` for build/git context.";
            $steps[] = 'If useful, `list_env_keys` (names only) and `get_logs` for runtime errors after a partial deploy.';
        } else {
            $steps[] = 'Call `list_deployments` (no application filter) to list currently in_progress/queued deployments, or ask the user for application_uuid / deployment_uuid.';
            $steps[] = 'Once you have a deployment_uuid, call `get_deployment` with include_log_summary=true.';
        }

        $steps[] = 'Explain the failure in plain language: root cause hypothesis, evidence from log_summary, and safe next steps for the human operator.';
        $steps[] = 'Never request or display secret env values, private keys, or full unbounded build logs.';

        $numbered = collect($steps)
            ->values()
            ->map(fn (string $step, int $i) => ($i + 1).'. '.$step)
            ->implode("\n");

        $body = "You are explaining a failed Coolify deployment for the authenticated team.\n\n{$numbered}";

        return Response::text($body);
    }

    public function arguments(): array
    {
        return [
            new Argument(
                name: 'deployment_uuid',
                description: 'Optional deployment UUID. Prefer this when known.',
                required: false,
            ),
            new Argument(
                name: 'application_uuid',
                description: 'Optional application UUID when deployment UUID is unknown.',
                required: false,
            ),
        ];
    }
}
