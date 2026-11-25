<?php

namespace App\Http\Controllers\Webhook;

use App\Actions\Application\CleanupPreviewDeployment;
use App\Exceptions\InvalidWebhookPayloadException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Webhook\Bitbucket\BitbucketWebhookContext;
use App\Http\Controllers\Webhook\Bitbucket\BitbucketWebhookVariantRegistry;
use App\Http\Controllers\Webhook\Concerns\MatchesManualWebhookApplications;
use App\Http\Controllers\Webhook\Concerns\RoutesConfigPushes;
use App\Http\Controllers\Webhook\Concerns\RoutesPushTriggers;
use App\Http\Controllers\Webhook\Concerns\ValidatesPreviewDeploymentRepository;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Services\DeployTriggers\PushTriggerRouter;
use App\Services\DeployTriggers\TriggerRoute;
use App\Services\ServerTransfer\TransferFinishChecklist;
use Exception;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class Bitbucket extends Controller
{
    use MatchesManualWebhookApplications;
    use RoutesConfigPushes;
    use RoutesPushTriggers;
    use ValidatesPreviewDeploymentRepository;

    /** @var array<string, PushTriggerRouter> branch => router */
    private array $pushTriggers = [];

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

            $full_names = collect($context->repositoryIdentifiers)
                ->map(fn (string $identifier): ?string => $this->manualWebhookRepositoryFullName($identifier))
                ->filter()
                ->unique(fn (string $fullName): string => mb_strtolower($fullName))
                ->values();
            if ($full_names->isEmpty()) {
                return response([
                    'status' => 'failed',
                    'message' => 'Nothing to do. Invalid repository.',
                ]);
            }
            $repositoryLabel = $full_names->implode(', ');

            $x_bitbucket_token = (string) $request->header('X-Hub-Signature', '');
            foreach ($context->pushedBranches as $branch => $pushedBranch) {
                $this->beginConfigPush(null, $full_names->all(), (string) $branch, $pushedBranch['commit'], function (string $secret) use ($request, $x_bitbucket_token): bool {
                    $parts = explode('=', $x_bitbucket_token, 2);

                    return count($parts) === 2 && $parts[0] === 'sha256' && hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $parts[1]);
                });
            }
            $failure_key = $this->manualWebhookFailureRateLimitKey($request, 'bitbucket', $full_names->first(), $context->branch);
            // A redelivery of the same signed payload is one guess.
            $failure_attempt = $this->manualWebhookSignedPayloadAttempt($request, $x_bitbucket_token);
            $applications = $this->bitbucketWebhookApplications($context, $full_names);
            if ($applications->isEmpty()) {
                return $this->unauthenticatedManualWebhookResponse($failure_key, $failure_attempt);
            }

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
                $rawPayload = $request->getContent();

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
                $payloadHash = hash_hmac('sha256', $rawPayload, $webhook_secret);
                if (! hash_equals($hash, $payloadHash)) {
                    auditLogWebhookFailure('bitbucket', 'invalid_signature', [
                        'application_uuid' => $application->uuid,
                        'application_name' => $application->name,
                        'repository' => $repositoryLabel,
                        'event' => $raw_bitbucket_event,
                    ]);
                    $return_payloads->push($this->unauthenticatedManualWebhookFailurePayload());

                    continue;
                }
                TransferFinishChecklist::recordWebhook('application', $application->uuid);
                $isFunctional = $application->destination->server->isFunctional();
                if (! $isFunctional) {
                    $return_payloads->push([
                        'application' => $application->name,
                        'status' => 'failed',
                        'message' => 'Server is not functional.',
                    ]);

                    continue;
                }

                $result = $this->dispatchBitbucketWebhookAction($application, $context, $repositoryLabel);
                if ($result !== null) {
                    $return_payloads->push($result);
                }
            }

            $this->dispatchPushTriggers(...array_values($this->pushTriggers));

            return $this->manualWebhookResponse($return_payloads, $failure_key, $failure_attempt);
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        } catch (InvalidWebhookPayloadException $e) {
            return response([
                'status' => 'failed',
                'message' => $e->getMessage(),
            ]);
        } catch (Exception $e) {
            return handleError($e);
        }
    }

    /**
     * Applications whose repository matches one of the payload repository names.
     * A push matches every pushed branch. A closed pull request matches every
     * branch, so its preview is removed even when the application branch changed.
     *
     * @param  Collection<int, string>  $fullNames
     * @return Collection<int, Application>
     */
    private function bitbucketWebhookApplications(BitbucketWebhookContext $context, Collection $fullNames): Collection
    {
        return $fullNames
            ->flatMap(function (string $fullName) use ($context): Collection {
                $query = Application::query();
                if ($context->action === 'push') {
                    $query->whereIn('git_branch', array_map('strval', array_keys($context->pushedBranches)));
                } elseif ($context->action !== 'preview_close') {
                    $query->where('git_branch', $context->branch);
                }

                return $this->manualWebhookApplications($query, $fullName);
            })
            ->unique('id')
            ->values();
    }

    /**
     * @return array<string, mixed>|null Null when the application must not answer.
     */
    private function dispatchBitbucketWebhookAction(Application $application, BitbucketWebhookContext $context, string $repositoryLabel): ?array
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

        $pushedBranch = $context->pushedBranches[$application->git_branch];
        if ($pushedBranch['skip_deploy']) {
            return [
                'application' => $application->name,
                'status' => 'skipped',
                'message' => 'All commits contain [skip cd] or [skip ci]. Skipping deployment.',
                'application_uuid' => $application->uuid,
                'application_name' => $application->name,
            ];
        }

        // Bitbucket sends no file lists, so evaluation compares the commits with an exact diff.
        $router = $this->pushTriggers[$application->git_branch] ??= PushTriggerRouter::forPush(
            $this->genericPushContext('bitbucket', 'bitbucket:'.mb_strtolower($repositoryLabel), $application->git_branch, $pushedBranch['commit'], null, null, null)
        );
        if ($router->route($application) === TriggerRoute::Evaluate) {
            $router->defer($application);

            return $router->responseEntry($application);
        }

        $deployment_uuid = new_public_id();
        $result = queue_application_deployment(
            application: $application,
            deployment_uuid: $deployment_uuid,
            commit: $pushedBranch['commit'],
            force_rebuild: false,
            is_webhook: true
        );

        if ($result['status'] === 'queue_full') {
            $this->dispatchPushTriggers(...array_values($this->pushTriggers));

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
            'deployment_uuid' => $deployment_uuid,
            'commit' => $pushedBranch['commit'],
            'repository' => $repositoryLabel,
        ]);

        return [
            'application' => $application->name,
            'status' => 'success',
            'message' => 'Deployment queued.',
        ];
    }

    /**
     * @return array<string, mixed>|null Null when the pull request comes from an untrusted repository.
     */
    private function handlePreviewDeploy(Application $application, BitbucketWebhookContext $context): ?array
    {
        if (! $application->isPRDeployable()) {
            return [
                'application' => $application->name,
                'status' => 'failed',
                'message' => 'Preview deployments disabled.',
            ];
        }

        if (! $this->isPreviewDeploymentRepositoryTrusted(
            $context->sourceRepository,
            $context->targetRepository,
            $context->webhookRepository,
            $application->settings->is_pr_deployments_public_enabled,
        )) {
            return null;
        }

        if ($context->skipDeployPr) {
            return [
                'application' => $application->name,
                'status' => 'skipped',
                'message' => 'PR title contains [skip cd] or [skip ci]. Skipping preview deployment.',
            ];
        }

        $deployment_uuid = new_public_id();
        $found = ApplicationPreview::where('application_id', $application->id)
            ->where('pull_request_id', $context->pullRequestId)
            ->first();

        if (! $found) {
            if ($application->build_pack === 'dockercompose') {
                $pr_app = ApplicationPreview::create([
                    'git_type' => 'bitbucket',
                    'application_id' => $application->id,
                    'pull_request_id' => $context->pullRequestId,
                    'pull_request_html_url' => $context->pullRequestHtmlUrl ?? '',
                    'docker_compose_domains' => $application->docker_compose_domains,
                ]);
                $pr_app->generate_preview_fqdn_compose();
            } else {
                $pr_app = ApplicationPreview::create([
                    'git_type' => 'bitbucket',
                    'application_id' => $application->id,
                    'pull_request_id' => $context->pullRequestId,
                    'pull_request_html_url' => $context->pullRequestHtmlUrl ?? '',
                ]);
                $pr_app->generate_preview_fqdn();
            }
        }
        ($found ?? $pr_app)->markForkPullRequest($this->isForkPreviewRepository(
            $context->sourceRepository,
            $context->targetRepository,
        ), $context->commit);

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
