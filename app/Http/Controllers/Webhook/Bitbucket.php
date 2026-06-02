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
use Visus\Cuid2\Cuid2;

class Bitbucket extends Controller
{
    use DetectsSkipDeployCommits;
    use MatchesManualWebhookApplications;

    public function manual(Request $request)
    {
        try {
            $return_payloads = collect([]);
            $payload = $request->collect();
            $headers = $request->headers->all();
            $x_bitbucket_token = data_get($headers, 'x-hub-signature.0', '');
            $x_bitbucket_event = data_get($headers, 'x-event-key.0', '');
            $handled_events = collect(['repo:push', 'pullrequest:updated', 'pullrequest:created', 'pullrequest:rejected', 'pullrequest:fulfilled']);
            if (! $handled_events->contains($x_bitbucket_event)) {
                return response([
                    'status' => 'failed',
                    'message' => 'Nothing to do. Event not handled.',
                ]);
            }
            if ($x_bitbucket_event === 'repo:push') {
                $branch = data_get($payload, 'push.changes.0.new.name');
                $full_name = data_get($payload, 'repository.full_name');
                $commit = data_get($payload, 'push.changes.0.new.target.hash');
                // Bitbucket webhooks ship up to 5 commits per change. Larger pushes
                // are evaluated only on the visible 5.
                $skip_deploy_commits = self::shouldSkipDeploy(
                    collect(data_get($payload, 'push.changes', []))
                        ->flatMap(fn ($change) => data_get($change, 'commits', []))
                        ->pluck('message')
                        ->filter()
                        ->values()
                        ->all()
                );

                if (! $branch) {
                    return response([
                        'status' => 'failed',
                        'message' => 'Nothing to do. No branch found in the request.',
                    ]);
                }
            }
            if ($x_bitbucket_event === 'pullrequest:updated' || $x_bitbucket_event === 'pullrequest:created' || $x_bitbucket_event === 'pullrequest:rejected' || $x_bitbucket_event === 'pullrequest:fulfilled') {
                $branch = data_get($payload, 'pullrequest.destination.branch.name');
                $base_branch = data_get($payload, 'pullrequest.source.branch.name');
                $full_name = data_get($payload, 'repository.full_name');
                $pull_request_id = data_get($payload, 'pullrequest.id');
                $pull_request_html_url = data_get($payload, 'pullrequest.links.html.href');
                $pull_request_title = data_get($payload, 'pullrequest.title');
                $skip_deploy_pr = self::shouldSkipDeployAny([$pull_request_title]);
                $commit = data_get($payload, 'pullrequest.source.commit.hash');
            }
            $full_name = $this->manualWebhookRepositoryFullName($full_name);
            if ($full_name === null) {
                return response([
                    'status' => 'failed',
                    'message' => 'Nothing to do. Invalid repository.',
                ]);
            }
            $applications = $this->manualWebhookApplications(Application::query()->where('git_branch', $branch), $full_name);
            if ($applications->isEmpty()) {
                return response([
                    'status' => 'failed',
                    'message' => "Nothing to do. No applications found with deploy key set, branch is '$branch' and Git Repository name has $full_name.",
                ]);
            }
            foreach ($applications as $application) {
                $webhook_secret = data_get($application, 'manual_webhook_secret_bitbucket');
                if (empty($webhook_secret)) {
                    auditLogWebhookFailure('bitbucket', 'webhook_secret_missing', [
                        'application_uuid' => $application->uuid,
                        'application_name' => $application->name,
                        'repository' => $full_name ?? null,
                        'event' => $x_bitbucket_event,
                    ]);
                    $return_payloads->push($this->unauthenticatedManualWebhookFailurePayload());

                    continue;
                }
                $payload = $request->getContent();

                $parts = explode('=', $x_bitbucket_token, 2);
                if (count($parts) !== 2 || $parts[0] !== 'sha256') {
                    auditLogWebhookFailure('bitbucket', 'malformed_signature', [
                        'application_uuid' => $application->uuid,
                        'application_name' => $application->name,
                        'repository' => $full_name ?? null,
                        'event' => $x_bitbucket_event,
                    ]);
                    $return_payloads->push($this->unauthenticatedManualWebhookFailurePayload());

                    continue;
                }
                $hash = $parts[1];
                $payloadHash = hash_hmac('sha256', $payload, $webhook_secret);
                if (! hash_equals($hash, $payloadHash) && ! isDev()) {
                    auditLogWebhookFailure('bitbucket', 'invalid_signature', [
                        'application_uuid' => $application->uuid,
                        'application_name' => $application->name,
                        'repository' => $full_name ?? null,
                        'event' => $x_bitbucket_event,
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
                if ($x_bitbucket_event === 'repo:push') {
                    if ($application->isDeployable()) {
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
                            commit: $commit,
                            force_rebuild: false,
                            is_webhook: true
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
                                'provider' => 'bitbucket',
                                'mode' => 'manual',
                                'application_uuid' => $application->uuid,
                                'application_name' => $application->name,
                                'deployment_uuid' => $deployment_uuid->toString(),
                                'commit' => $commit,
                                'repository' => $full_name ?? null,
                            ]);
                            $return_payloads->push([
                                'application' => $application->name,
                                'status' => 'success',
                                'message' => 'Deployment queued.',
                            ]);
                        }
                    } else {
                        $return_payloads->push([
                            'application' => $application->name,
                            'status' => 'failed',
                            'message' => 'Auto deployment disabled.',
                        ]);
                    }
                }
                if ($x_bitbucket_event === 'pullrequest:created' || $x_bitbucket_event === 'pullrequest:updated') {
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
                                    'git_type' => 'bitbucket',
                                    'application_id' => $application->id,
                                    'pull_request_id' => $pull_request_id,
                                    'pull_request_html_url' => $pull_request_html_url,
                                    'docker_compose_domains' => $application->docker_compose_domains,
                                ]);
                                $pr_app->generate_preview_fqdn_compose();
                            } else {
                                $pr_app = ApplicationPreview::create([
                                    'git_type' => 'bitbucket',
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
                            commit: $commit,
                            is_webhook: true,
                            git_type: 'bitbucket'
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
                if ($x_bitbucket_event === 'pullrequest:rejected' || $x_bitbucket_event === 'pullrequest:fulfilled') {
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

            return response($return_payloads);
        } catch (Exception $e) {
            return handleError($e);
        }
    }
}
