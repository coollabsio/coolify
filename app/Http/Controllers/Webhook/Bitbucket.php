<?php

namespace App\Http\Controllers\Webhook;

use App\Actions\Application\CleanupPreviewDeployment;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Webhook\Bitbucket\BitbucketWebhookContext;
use App\Http\Controllers\Webhook\Bitbucket\BitbucketWebhookVariantRegistry;
use App\Http\Controllers\Webhook\Concerns\MatchesManualWebhookApplications;
use App\Models\Application;
use App\Models\ApplicationPreview;
use Exception;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Visus\Cuid2\Cuid2;

class Bitbucket extends Controller
{
    use MatchesManualWebhookApplications;

    public function manual(Request $request, ?BitbucketWebhookVariantRegistry $registry = null)
    {
        try {
            $return_payloads = collect([]);
            $registry ??= new BitbucketWebhookVariantRegistry;
            $variant = $registry->resolve($request);
            $raw_bitbucket_event = $variant->eventKey($request);

            if ($raw_bitbucket_event === '' || ! in_array($raw_bitbucket_event, $variant->handledEventKeys(), true)) {
                return response([
                    'status' => 'failed',
                    'message' => 'Nothing to do. Event not handled.',
                ]);
            }

            $context = $variant->parse($request);

            if ($context === null) {
                return response([
                    'status' => 'failed',
                    'message' => $variant->unhandledBranchMessage($raw_bitbucket_event),
                ]);
            }

            $applications = Application::query()
                ->where('git_branch', $context->branch)
                ->get()
                ->filter(function (Application $application) use ($context): bool {
                    foreach ($context->repositoryIdentifiers as $identifier) {
                        $normalized = $this->normalizeManualWebhookRepositoryPath($identifier);
                        if ($this->manualWebhookRepositoryMatches($application->git_repository, $normalized)) {
                            return true;
                        }
                    }

                    return false;
                })
                ->values();

            $repositoryLabel = $context->repositoryLabel();

            if ($applications->isEmpty()) {
                return response([
                    'status' => 'failed',
                    'message' => "Nothing to do. No applications found with deploy key set, branch is '{$context->branch}' and Git Repository name has {$repositoryLabel}.",
                ]);
            }

            $headers = $request->headers->all();
            $x_bitbucket_token = data_get($headers, 'x-hub-signature.0', '');

            foreach ($applications as $application) {
                $webhook_secret = data_get($application, 'manual_webhook_secret_bitbucket');
                if (empty($webhook_secret)) {
                    auditLogWebhookFailure('bitbucket', 'webhook_secret_missing', [
                        'application_uuid' => $application->uuid,
                        'application_name' => $application->name,
                        'repository' => $repositoryLabel,
                        'event' => $raw_bitbucket_event,
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
                        'repository' => $repositoryLabel,
                        'event' => $raw_bitbucket_event,
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
                        'repository' => $repositoryLabel,
                        'event' => $raw_bitbucket_event,
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

                $return_payloads->push($this->dispatchBitbucketWebhookAction($application, $context, $repositoryLabel));
            }

            return response($return_payloads);
        } catch (Exception $e) {
            return handleError($e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatchBitbucketWebhookAction(Application $application, BitbucketWebhookContext $context, string $repositoryLabel): array
    {
        return match ($context->action) {
            'push' => $this->handlePush($application, $context, $repositoryLabel),
            'preview_deploy' => $this->handlePreviewDeploy($application, $context),
            'preview_close' => $this->handlePreviewClose($application, $context),
            default => [
                'application' => $application->name,
                'status' => 'failed',
                'message' => 'Nothing to do. Event not handled.',
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function handlePush(Application $application, BitbucketWebhookContext $context, string $repositoryLabel): array
    {
        if (! $application->isDeployable()) {
            return [
                'application' => $application->name,
                'status' => 'failed',
                'message' => 'Auto deployment disabled.',
            ];
        }

        if ($context->skipDeployCommits) {
            return [
                'application' => $application->name,
                'status' => 'skipped',
                'message' => 'All commits contain [skip cd] or [skip ci]. Skipping deployment.',
                'application_uuid' => $application->uuid,
                'application_name' => $application->name,
            ];
        }

        $deployment_uuid = new Cuid2;
        $result = queue_application_deployment(
            application: $application,
            deployment_uuid: $deployment_uuid,
            commit: $context->commit,
            force_rebuild: false,
            is_webhook: true
        );

        if ($result['status'] === 'queue_full') {
            throw new HttpResponseException(response($result['message'], 429)->header('Retry-After', 60));
        }

        if ($result['status'] === 'skipped') {
            return [
                'application' => $application->name,
                'status' => 'skipped',
                'message' => $result['message'],
            ];
        }

        auditLog('webhook.deployment.queued', [
            'provider' => 'bitbucket',
            'mode' => 'manual',
            'application_uuid' => $application->uuid,
            'application_name' => $application->name,
            'deployment_uuid' => $deployment_uuid->toString(),
            'commit' => $context->commit,
            'repository' => $repositoryLabel,
        ]);

        return [
            'application' => $application->name,
            'status' => 'success',
            'message' => 'Deployment queued.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function handlePreviewDeploy(Application $application, BitbucketWebhookContext $context): array
    {
        if (! $application->isPRDeployable()) {
            return [
                'application' => $application->name,
                'status' => 'failed',
                'message' => 'Preview deployments disabled.',
            ];
        }

        if ($context->skipDeployPr) {
            return [
                'application' => $application->name,
                'status' => 'skipped',
                'message' => 'PR title contains [skip cd] or [skip ci]. Skipping preview deployment.',
            ];
        }

        $deployment_uuid = new Cuid2;
        $found = ApplicationPreview::where('application_id', $application->id)
            ->where('pull_request_id', $context->pullRequestId)
            ->first();

        if (! $found) {
            if ($application->build_pack === 'dockercompose') {
                $pr_app = ApplicationPreview::create([
                    'git_type' => 'bitbucket',
                    'application_id' => $application->id,
                    'pull_request_id' => $context->pullRequestId,
                    'pull_request_html_url' => $context->pullRequestHtmlUrl,
                    'docker_compose_domains' => $application->docker_compose_domains,
                ]);
                $pr_app->generate_preview_fqdn_compose();
            } else {
                $pr_app = ApplicationPreview::create([
                    'git_type' => 'bitbucket',
                    'application_id' => $application->id,
                    'pull_request_id' => $context->pullRequestId,
                    'pull_request_html_url' => $context->pullRequestHtmlUrl,
                ]);
                $pr_app->generate_preview_fqdn();
            }
        }

        $result = queue_application_deployment(
            application: $application,
            pull_request_id: $context->pullRequestId,
            deployment_uuid: $deployment_uuid,
            force_rebuild: false,
            commit: $context->commit,
            is_webhook: true,
            git_type: 'bitbucket'
        );

        if ($result['status'] === 'queue_full') {
            throw new HttpResponseException(response($result['message'], 429)->header('Retry-After', 60));
        }

        if ($result['status'] === 'skipped') {
            return [
                'application' => $application->name,
                'status' => 'skipped',
                'message' => $result['message'],
            ];
        }

        return [
            'application' => $application->name,
            'status' => 'success',
            'message' => 'Preview deployment queued.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function handlePreviewClose(Application $application, BitbucketWebhookContext $context): array
    {
        $found = ApplicationPreview::where('application_id', $application->id)
            ->where('pull_request_id', $context->pullRequestId)
            ->first();

        if (! $found) {
            return [
                'application' => $application->name,
                'status' => 'failed',
                'message' => 'No preview deployment found.',
            ];
        }

        CleanupPreviewDeployment::run($application, $context->pullRequestId, $found);

        return [
            'application' => $application->name,
            'status' => 'success',
            'message' => 'Preview deployment closed.',
        ];
    }
}
