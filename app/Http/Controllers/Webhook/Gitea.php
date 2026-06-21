<?php

namespace App\Http\Controllers\Webhook;

use App\Actions\Application\CleanupPreviewDeployment;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Webhook\Concerns\DetectsSkipDeployCommits;
use App\Http\Controllers\Webhook\Concerns\MatchesManualWebhookApplications;
use App\Models\Application;
use App\Models\ApplicationPreview;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Visus\Cuid2\Cuid2;

class Gitea extends Controller
{
    use DetectsSkipDeployCommits;
    use MatchesManualWebhookApplications;

    public function manual(Request $request)
    {
        try {
            $return_payloads = collect([]);
            $x_gitea_delivery = request()->header('X-Gitea-Delivery');
            $x_gitea_event = Str::lower($request->header('X-Gitea-Event'));
            $x_hub_signature_256 = Str::after($request->header('X-Hub-Signature-256'), 'sha256=');
            $content_type = $request->header('Content-Type');
            $payload = $request->collect();
            if ($x_gitea_event === 'ping') {
                // Just pong
                return response('pong');
            }

            if ($content_type !== 'application/json') {
                $payload = json_decode(data_get($payload, 'payload'), true);
            }
            if ($x_gitea_event === 'push') {
                $branch = data_get($payload, 'ref');
                $full_name = data_get($payload, 'repository.full_name');
                if (Str::isMatch('/refs\/heads\/*/', $branch)) {
                    $branch = Str::after($branch, 'refs/heads/');
                }
                $added_files = data_get($payload, 'commits.*.added');
                $removed_files = data_get($payload, 'commits.*.removed');
                $modified_files = data_get($payload, 'commits.*.modified');
                $changed_files = collect($added_files)->concat($removed_files)->concat($modified_files)->unique()->flatten();
                $skip_deploy_commits = self::shouldSkipDeploy(data_get($payload, 'commits.*.message', []));
            }
            if ($x_gitea_event === 'pull_request') {
                $action = data_get($payload, 'action');
                $full_name = data_get($payload, 'repository.full_name');
                $pull_request_id = data_get($payload, 'number');
                $pull_request_html_url = data_get($payload, 'pull_request.html_url');
                $pull_request_title = data_get($payload, 'pull_request.title');
                $skip_deploy_pr = self::shouldSkipDeployAny([$pull_request_title]);
                $branch = data_get($payload, 'pull_request.head.ref');
                $base_branch = data_get($payload, 'pull_request.base.ref');
            }
            if (! $branch) {
                return response('Nothing to do. No branch found in the request.');
            }
            $full_name = $this->manualWebhookRepositoryFullName($full_name);
            if ($full_name === null) {
                return response('Nothing to do. Invalid repository.');
            }
            $applications = Application::query();
            if ($x_gitea_event === 'push') {
                $applications = $this->manualWebhookApplications($applications->where('git_branch', $branch), $full_name);
                if ($applications->isEmpty()) {
                    return response("Nothing to do. No applications found with deploy key set, branch is '$branch' and Git Repository name has $full_name.");
                }
            }
            if ($x_gitea_event === 'pull_request') {
                $applications = $this->manualWebhookApplications($applications->where('git_branch', $base_branch), $full_name);
                if ($applications->isEmpty()) {
                    return response("Nothing to do. No applications found with branch '$base_branch'.");
                }
            }
            foreach ($applications as $application) {
                $webhook_secret = data_get($application, 'manual_webhook_secret_gitea');
                if (empty($webhook_secret)) {
                    auditLogWebhookFailure('gitea', 'webhook_secret_missing', [
                        'application_uuid' => $application->uuid,
                        'application_name' => $application->name,
                        'repository' => $full_name ?? null,
                        'event' => $x_gitea_event,
                    ]);
                    $return_payloads->push($this->unauthenticatedManualWebhookFailurePayload());

                    continue;
                }
                $hmac = hash_hmac('sha256', $request->getContent(), $webhook_secret);
                if (! hash_equals($x_hub_signature_256, $hmac) && ! isDev()) {
                    auditLogWebhookFailure('gitea', 'invalid_signature', [
                        'application_uuid' => $application->uuid,
                        'application_name' => $application->name,
                        'repository' => $full_name ?? null,
                        'event' => $x_gitea_event,
                    ]);
                    $return_payloads->push($this->unauthenticatedManualWebhookFailurePayload());

                    continue;
                }
                $isFunctional = $application->destination->server->isFunctional();
                if (! $isFunctional) {
                    $return_payloads->push([
                        'application' => $application->name,
                        'status' => 'failed',
                        'message' => 'Server is not functional.',
                    ]);

                    continue;
                }
                if ($x_gitea_event === 'push') {
                    if ($application->isDeployable()) {
                        $is_watch_path_triggered = $application->isWatchPathsTriggered($changed_files);
                        if ($is_watch_path_triggered || blank($application->watch_paths)) {
                            if ($skip_deploy_commits ?? false) {
                                $return_payloads->push([
                                    'application' => $application->name,
                                    'status' => 'skipped',
                                    'message' => 'All commits contain [skip cd] or [skip ci]. Skipping deployment.',
                                    'application_uuid' => $application->uuid,
                                    'application_name' => $application->name,
                                ]);

                                continue;
                            }
                            $deployment_uuid = new Cuid2;
                            $result = queue_application_deployment(
                                application: $application,
                                deployment_uuid: $deployment_uuid,
                                force_rebuild: false,
                                commit: data_get($payload, 'after', 'HEAD'),
                                is_webhook: true,
                            );
                            if ($result['status'] === 'queue_full') {
                                return response($result['message'], 429)->header('Retry-After', 60);
                            } elseif ($result['status'] === 'skipped') {
                                $return_payloads->push([
                                    'application' => $application->name,
                                    'status' => 'skipped',
                                    'message' => $result['message'],
                                ]);
                            } else {
                                auditLog('webhook.deployment.queued', [
                                    'provider' => 'gitea',
                                    'mode' => 'manual',
                                    'application_uuid' => $application->uuid,
                                    'application_name' => $application->name,
                                    'deployment_uuid' => $deployment_uuid->toString(),
                                    'commit' => data_get($payload, 'after'),
                                    'repository' => $full_name ?? null,
                                ]);
                                $return_payloads->push([
                                    'status' => 'success',
                                    'message' => 'Deployment queued.',
                                    'application_uuid' => $application->uuid,
                                    'application_name' => $application->name,
                                ]);
                            }
                        } else {
                            $paths = str($application->watch_paths)->explode("\n");
                            $return_payloads->push([
                                'status' => 'failed',
                                'message' => 'Changed files do not match watch paths. Ignoring deployment.',
                                'application_uuid' => $application->uuid,
                                'application_name' => $application->name,
                                'details' => [
                                    'changed_files' => $changed_files,
                                    'watch_paths' => $paths,
                                ],
                            ]);
                        }
                    } else {
                        $return_payloads->push([
                            'status' => 'failed',
                            'message' => 'Deployments disabled.',
                            'application_uuid' => $application->uuid,
                            'application_name' => $application->name,
                        ]);
                    }
                }
                if ($x_gitea_event === 'pull_request') {
                    if ($action === 'opened' || $action === 'synchronized' || $action === 'reopened') {
                        if ($application->isPRDeployable()) {
                            if ($skip_deploy_pr ?? false) {
                                $return_payloads->push([
                                    'application' => $application->name,
                                    'status' => 'skipped',
                                    'message' => 'PR title contains [skip cd] or [skip ci]. Skipping preview deployment.',
                                ]);

                                continue;
                            }
                            $deployment_uuid = new Cuid2;
                            $found = ApplicationPreview::where('application_id', $application->id)->where('pull_request_id', $pull_request_id)->first();
                            if (! $found) {
                                if ($application->build_pack === 'dockercompose') {
                                    $pr_app = ApplicationPreview::create([
                                        'git_type' => 'gitea',
                                        'application_id' => $application->id,
                                        'pull_request_id' => $pull_request_id,
                                        'pull_request_html_url' => $pull_request_html_url,
                                        'docker_compose_domains' => $application->docker_compose_domains,
                                    ]);
                                    $pr_app->generate_preview_fqdn_compose();
                                } else {
                                    $pr_app = ApplicationPreview::create([
                                        'git_type' => 'gitea',
                                        'application_id' => $application->id,
                                        'pull_request_id' => $pull_request_id,
                                        'pull_request_html_url' => $pull_request_html_url,
                                    ]);
                                    $pr_app->generate_preview_fqdn();
                                }
                            }
                            $result = queue_application_deployment(
                                application: $application,
                                pull_request_id: $pull_request_id,
                                deployment_uuid: $deployment_uuid,
                                force_rebuild: false,
                                commit: data_get($payload, 'head.sha', 'HEAD'),
                                is_webhook: true,
                                git_type: 'gitea'
                            );
                            if ($result['status'] === 'queue_full') {
                                return response($result['message'], 429)->header('Retry-After', 60);
                            } elseif ($result['status'] === 'skipped') {
                                $return_payloads->push([
                                    'application' => $application->name,
                                    'status' => 'skipped',
                                    'message' => $result['message'],
                                ]);
                            } else {
                                $return_payloads->push([
                                    'application' => $application->name,
                                    'status' => 'success',
                                    'message' => 'Preview deployment queued.',
                                ]);
                            }
                        } else {
                            $return_payloads->push([
                                'application' => $application->name,
                                'status' => 'failed',
                                'message' => 'Preview deployments disabled.',
                            ]);
                        }
                    }
                    if ($action === 'closed') {
                        $found = ApplicationPreview::where('application_id', $application->id)->where('pull_request_id', $pull_request_id)->first();
                        if ($found) {
                            // Use comprehensive cleanup that cancels active deployments,
                            // kills helper containers, and removes all PR containers
                            CleanupPreviewDeployment::run($application, $pull_request_id, $found);

                            $return_payloads->push([
                                'application' => $application->name,
                                'status' => 'success',
                                'message' => 'Preview deployment closed.',
                            ]);
                        } else {
                            $return_payloads->push([
                                'application' => $application->name,
                                'status' => 'failed',
                                'message' => 'No preview deployment found.',
                            ]);
                        }
                    }
                }
            }

            return response($return_payloads);
        } catch (Exception $e) {
            return handleError($e);
        }
    }
}
