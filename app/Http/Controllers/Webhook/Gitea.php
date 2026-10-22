<?php

namespace App\Http\Controllers\Webhook;

use App\Actions\Application\CleanupPreviewDeployment;
use App\Exceptions\InvalidWebhookPayloadException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Webhook\Concerns\DetectsSkipDeployCommits;
use App\Http\Controllers\Webhook\Concerns\MatchesManualWebhookApplications;
use App\Http\Controllers\Webhook\Concerns\ReadsWebhookPushPayload;
use App\Http\Controllers\Webhook\Concerns\ValidatesPreviewDeploymentRepository;
use App\Livewire\Source\Gitea\Change as GiteaSource;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\GiteaApp;
use App\Services\ServerTransfer\TransferFinishChecklist;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class Gitea extends Controller
{
    use DetectsSkipDeployCommits;
    use MatchesManualWebhookApplications;
    use ReadsWebhookPushPayload;
    use ValidatesPreviewDeploymentRepository;

    public function redirect(Request $request)
    {
        try {
            $code = $request->query('code');
            $state = $request->query('state');

            if (! $code || ! $state) {
                return redirect()->route('source.all')->with('error', 'Invalid Gitea OAuth callback. Missing code or state.');
            }

            // Validate the one-time, team-bound state (not a guessable source UUID) to stop forged callbacks from overwriting a source's tokens.
            $payload = Cache::pull(GiteaSource::oauthStateCacheKey($state));
            $team_id = $request->user()?->currentTeam()?->id;
            if (! is_array($payload) || is_null($team_id) || (int) data_get($payload, 'team_id') !== (int) $team_id) {
                return redirect()->route('source.all')->with('error', 'Invalid or expired Gitea OAuth state. Please start the authorization again.');
            }

            $giteaApp = GiteaApp::whereKey(data_get($payload, 'gitea_app_id'))->firstOrFail();

            // Only users who may administer the source can complete OAuth and store tokens.
            if (! $request->user()->can('update', $giteaApp)) {
                return redirect()->route('source.all')->with('error', 'You are not authorized to connect this Gitea App.');
            }

            $baseUrl = rtrim($giteaApp->html_url, '/');

            $response = Http::GitSource($baseUrl)->asForm()->post("{$baseUrl}/login/oauth/access_token", [
                'client_id' => $giteaApp->client_id,
                'client_secret' => $giteaApp->client_secret,
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $giteaApp->redirect_uri,
            ]);

            if (! $response->successful()) {
                $error = data_get($response->json(), 'error_description', 'Token exchange failed');

                return redirect()->route('source.gitea.show', ['gitea_app_uuid' => $giteaApp->uuid])
                    ->with('error', "Gitea OAuth failed: {$error}");
            }

            $data = $response->json();
            $giteaApp->update([
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'],
                'expires_at' => time() + ($data['expires_in'] ?? 3600),
            ]);

            return redirect()->route('source.gitea.show', ['gitea_app_uuid' => $giteaApp->uuid]);
        } catch (Exception $e) {
            return redirect()->route('source.all')->with('error', $e->getMessage());
        }
    }

    public function normal(Request $request)
    {
        try {
            $return_payloads = collect([]);
            $x_gitea_event = Str::lower((string) $request->header('X-Gitea-Event'));

            if ($x_gitea_event === 'ping') {
                return response('pong');
            }

            if (! in_array($x_gitea_event, ['push', 'pull_request'], true)) {
                return response([
                    'status' => 'failed',
                    'message' => 'Event not allowed. Only push and pull_request events are allowed.',
                ]);
            }

            $signature = (string) $request->header('X-Gitea-Signature');
            if ($signature === '') {
                $signature = Str::after((string) $request->header('X-Hub-Signature-256'), 'sha256=');
            }

            if ($signature === '') {
                auditLogWebhookFailure('gitea', 'webhook_signature_missing', [
                    'event' => $x_gitea_event,
                ]);

                return response([
                    'status' => 'failed',
                    'message' => 'Missing webhook signature.',
                ], 401);
            }

            $gitea_app = GiteaApp::findBySignature($request->getContent(), $signature);
            if (! $gitea_app) {
                auditLogWebhookFailure('gitea', 'invalid_signature', [
                    'event' => $x_gitea_event,
                ]);

                return response([
                    'status' => 'failed',
                    'message' => 'Invalid webhook signature.',
                ], 401);
            }

            $payload = $request->collect();
            if (! $request->isJson()) {
                $form_payload = data_get($payload, 'payload');
                $payload = is_string($form_payload) ? json_decode($form_payload, true) : null;
            }

            $repository_id = $this->webhookPayloadDatabaseId($payload, 'repository.id', required: true);
            $applications = Application::where('source_id', $gitea_app->id)
                ->where('source_type', GiteaApp::class)
                ->where('repository_project_id', $repository_id);

            if ($x_gitea_event === 'push') {
                $branch = $this->webhookPushBranch(data_get($payload, 'ref'));
                if (! $branch) {
                    return response([
                        'status' => 'failed',
                        'message' => 'No branch found in the request.',
                    ]);
                }
                if ($this->isWebhookBranchDeletionPush($payload)) {
                    return response([
                        'status' => 'skipped',
                        'message' => 'Nothing to do. Branch deleted.',
                    ]);
                }
                $commit = $this->webhookCommitSha($payload, 'after');

                $applications = $applications->where('git_branch', $branch)->get();
                $changed_files = $this->webhookPushChangedFiles($payload);
                $skip_deploy_commits = self::shouldSkipDeploy($this->webhookPushCommitMessages($payload));

                foreach ($applications as $application) {
                    if (! $application->destination->server->isFunctional()) {
                        $return_payloads->push([
                            'application' => $application->name,
                            'status' => 'failed',
                            'message' => 'Server is not functional',
                        ]);

                        continue;
                    }

                    if (! $application->isDeployable()) {
                        $return_payloads->push([
                            'application' => $application->name,
                            'status' => 'failed',
                            'message' => 'Deployments disabled',
                        ]);

                        continue;
                    }

                    if (! $this->webhookPushMatchesWatchPaths($application, $changed_files)) {
                        $return_payloads->push([
                            'application' => $application->name,
                            'status' => 'failed',
                            'message' => 'Changed files do not match watch paths.',
                        ]);

                        continue;
                    }

                    if ($skip_deploy_commits) {
                        $return_payloads->push([
                            'application' => $application->name,
                            'status' => 'skipped',
                            'message' => 'All commits contain [skip cd] or [skip ci].',
                        ]);

                        continue;
                    }

                    $deployment_uuid = new_public_id();
                    $result = queue_application_deployment(
                        application: $application,
                        deployment_uuid: $deployment_uuid,
                        commit: $commit ?? 'HEAD',
                        force_rebuild: false,
                        is_webhook: true,
                    );

                    if ($result['status'] === 'queue_full') {
                        return response($result['message'], 429)->header('Retry-After', 60);
                    }

                    auditLog('webhook.deployment.queued', [
                        'provider' => 'gitea',
                        'mode' => 'app',
                        'application_uuid' => $application->uuid,
                        'application_name' => $application->name,
                        'deployment_uuid' => $deployment_uuid,
                        'commit' => $commit,
                    ]);

                    $return_payloads->push([
                        'application' => $application->name,
                        'status' => $result['status'] ?? 'success',
                        'message' => $result['message'] ?? 'Deployment queued.',
                    ]);
                }
            }

            if ($x_gitea_event === 'pull_request') {
                $branch = $this->webhookString(data_get($payload, 'pull_request.head.ref'));
                $base_branch = $this->webhookString(data_get($payload, 'pull_request.base.ref'));
                if (! $branch || ! $base_branch) {
                    return response([
                        'status' => 'failed',
                        'message' => 'No branch found in the request.',
                    ]);
                }
                [
                    'action' => $action,
                    'pull_request_id' => $pull_request_id,
                    'pull_request_html_url' => $pull_request_html_url,
                    'commit' => $commit,
                    'skip_deploy' => $skip_deploy_pr,
                ] = $this->readPullRequestPayload($payload);

                $applications = $applications->where('git_branch', $base_branch)->get();

                foreach ($applications as $application) {
                    if (! $application->destination->server->isFunctional()) {
                        $return_payloads->push([
                            'application' => $application->name,
                            'status' => 'failed',
                            'message' => 'Server is not functional',
                        ]);

                        continue;
                    }

                    if (in_array($action, ['opened', 'synchronized', 'reopened'], true)) {
                        if (! $application->isPRDeployable()) {
                            $return_payloads->push([
                                'application' => $application->name,
                                'status' => 'failed',
                                'message' => 'Preview deployments disabled',
                            ]);

                            continue;
                        }

                        if (! $this->isPreviewDeploymentRepositoryTrusted(
                            data_get($payload, 'pull_request.head.repo.id'),
                            data_get($payload, 'pull_request.base.repo.id'),
                            data_get($payload, 'repository.id'),
                            $application->settings->is_pr_deployments_public_enabled,
                        )) {
                            continue;
                        }

                        if ($skip_deploy_pr) {
                            $return_payloads->push([
                                'application' => $application->name,
                                'status' => 'skipped',
                                'message' => 'PR title contains [skip cd] or [skip ci].',
                            ]);

                            continue;
                        }

                        $deployment_uuid = new_public_id();
                        $found = ApplicationPreview::where('application_id', $application->id)
                            ->where('pull_request_id', $pull_request_id)
                            ->first();

                        if (! $found) {
                            if ($application->build_pack === 'dockercompose') {
                                $pr_app = ApplicationPreview::create([
                                    'git_type' => 'gitea',
                                    'application_id' => $application->id,
                                    'pull_request_id' => $pull_request_id,
                                    'pull_request_html_url' => $pull_request_html_url ?? '',
                                    'docker_compose_domains' => $application->docker_compose_domains,
                                ]);
                                $pr_app->generate_preview_fqdn_compose();
                            } else {
                                $pr_app = ApplicationPreview::create([
                                    'git_type' => 'gitea',
                                    'application_id' => $application->id,
                                    'pull_request_id' => $pull_request_id,
                                    'pull_request_html_url' => $pull_request_html_url ?? '',
                                ]);
                                $pr_app->generate_preview_fqdn();
                            }
                        }

                        $result = queue_application_deployment(
                            application: $application,
                            pull_request_id: $pull_request_id,
                            deployment_uuid: $deployment_uuid,
                            commit: $commit ?? 'HEAD',
                            force_rebuild: false,
                            is_webhook: true,
                            git_type: 'gitea',
                        );

                        if ($result['status'] === 'queue_full') {
                            return response($result['message'], 429)->header('Retry-After', 60);
                        }

                        $return_payloads->push([
                            'application' => $application->name,
                            'status' => $result['status'] ?? 'success',
                            'message' => $result['message'] ?? 'Preview deployment queued.',
                        ]);
                    } elseif ($action === 'closed') {
                        $found = ApplicationPreview::where('application_id', $application->id)
                            ->where('pull_request_id', $pull_request_id)
                            ->first();

                        if ($found) {
                            CleanupPreviewDeployment::run($application, $pull_request_id, $found);
                            $return_payloads->push([
                                'application' => $application->name,
                                'status' => 'success',
                                'message' => 'Preview deployment closed.',
                            ]);
                        }
                    }
                }
            }

            return response($return_payloads);
        } catch (InvalidWebhookPayloadException $e) {
            return response([
                'status' => 'failed',
                'message' => $e->getMessage(),
            ]);
        } catch (Exception $e) {
            return handleError($e);
        }
    }

    public function manual(Request $request)
    {
        try {
            $return_payloads = collect([]);
            $x_gitea_delivery = request()->header('X-Gitea-Delivery');
            $x_gitea_event = Str::lower((string) $request->header('X-Gitea-Event'));
            $x_hub_signature_256 = Str::after((string) $request->header('X-Hub-Signature-256'), 'sha256=');
            $content_type = $request->header('Content-Type');
            $payload = $request->collect();
            if ($x_gitea_event === 'ping') {
                // Just pong
                return response('pong');
            }
            if (! in_array($x_gitea_event, ['push', 'pull_request'], true)) {
                return response("Nothing to do. Event '$x_gitea_event' is not supported.");
            }

            if ($content_type !== 'application/json') {
                $form_payload = data_get($payload, 'payload');
                $payload = is_string($form_payload) ? json_decode($form_payload, true) : null;
            }
            if ($x_gitea_event === 'push') {
                $branch = $this->webhookPushBranch(data_get($payload, 'ref'));
                $full_name = data_get($payload, 'repository.full_name');
                $commit = $this->webhookCommitSha($payload, 'after');
                $changed_files = $this->webhookPushChangedFiles($payload);
                $skip_deploy_commits = self::shouldSkipDeploy($this->webhookPushCommitMessages($payload));
            }
            if ($x_gitea_event === 'pull_request') {
                $full_name = data_get($payload, 'repository.full_name');
                $branch = $this->webhookString(data_get($payload, 'pull_request.head.ref'));
                $base_branch = $this->webhookString(data_get($payload, 'pull_request.base.ref'));
                [
                    'action' => $action,
                    'pull_request_id' => $pull_request_id,
                    'pull_request_html_url' => $pull_request_html_url,
                    'commit' => $commit,
                    'skip_deploy' => $skip_deploy_pr,
                ] = $this->readPullRequestPayload($payload);
            }
            if (! $branch || ($x_gitea_event === 'pull_request' && ! $base_branch)) {
                return response('Nothing to do. No branch found in the request.');
            }
            // A deleted branch has no commit to deploy. No secret is checked here.
            if ($x_gitea_event === 'push' && $this->isWebhookBranchDeletionPush($payload)) {
                return response('Nothing to do. Branch deleted.');
            }
            $full_name = $this->manualWebhookRepositoryFullName($full_name);
            if ($full_name === null) {
                return response('Nothing to do. Invalid repository.');
            }
            $matched_branch = $x_gitea_event === 'pull_request' ? $base_branch : $branch;
            $failure_key = $this->manualWebhookFailureRateLimitKey($request, 'gitea', $full_name, $matched_branch);
            // A redelivery of the same signed payload is one guess.
            $failure_attempt = $this->manualWebhookSignedPayloadAttempt($request, $x_hub_signature_256);
            $applications = Application::query();
            if ($x_gitea_event === 'push') {
                $applications = $this->manualWebhookApplications($applications->where('git_branch', $branch), $full_name);
                if ($applications->isEmpty()) {
                    return $this->unauthenticatedManualWebhookResponse($failure_key, $failure_attempt);
                }
            }
            if ($x_gitea_event === 'pull_request') {
                if ($action !== 'closed') {
                    $applications->where('git_branch', $base_branch);
                }
                $applications = $this->manualWebhookApplications($applications, $full_name);
                if ($applications->isEmpty()) {
                    return $this->unauthenticatedManualWebhookResponse($failure_key, $failure_attempt);
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
                if (! hash_equals($x_hub_signature_256, $hmac)) {
                    auditLogWebhookFailure('gitea', 'invalid_signature', [
                        'application_uuid' => $application->uuid,
                        'application_name' => $application->name,
                        'repository' => $full_name ?? null,
                        'event' => $x_gitea_event,
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
                if ($x_gitea_event === 'push') {
                    if ($application->isDeployable()) {
                        if ($this->webhookPushMatchesWatchPaths($application, $changed_files)) {
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
                            $deployment_uuid = new_public_id();
                            $result = queue_application_deployment(
                                application: $application,
                                deployment_uuid: $deployment_uuid,
                                force_rebuild: false,
                                commit: $commit ?? 'HEAD',
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
                                    'deployment_uuid' => $deployment_uuid,
                                    'commit' => $commit,
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
                            if (! $this->isPreviewDeploymentRepositoryTrusted(
                                data_get($payload, 'pull_request.head.repo.id'),
                                data_get($payload, 'pull_request.base.repo.id'),
                                data_get($payload, 'repository.id'),
                                $application->settings->is_pr_deployments_public_enabled,
                            )) {
                                continue;
                            }

                            if ($skip_deploy_pr ?? false) {
                                $return_payloads->push([
                                    'application' => $application->name,
                                    'status' => 'skipped',
                                    'message' => 'PR title contains [skip cd] or [skip ci]. Skipping preview deployment.',
                                ]);

                                continue;
                            }
                            $deployment_uuid = new_public_id();
                            $found = ApplicationPreview::where('application_id', $application->id)->where('pull_request_id', $pull_request_id)->first();
                            if (! $found) {
                                if ($application->build_pack === 'dockercompose') {
                                    $pr_app = ApplicationPreview::create([
                                        'git_type' => 'gitea',
                                        'application_id' => $application->id,
                                        'pull_request_id' => $pull_request_id,
                                        'pull_request_html_url' => $pull_request_html_url ?? '',
                                        'docker_compose_domains' => $application->docker_compose_domains,
                                    ]);
                                    $pr_app->generate_preview_fqdn_compose();
                                } else {
                                    $pr_app = ApplicationPreview::create([
                                        'git_type' => 'gitea',
                                        'application_id' => $application->id,
                                        'pull_request_id' => $pull_request_id,
                                        'pull_request_html_url' => $pull_request_html_url ?? '',
                                    ]);
                                    $pr_app->generate_preview_fqdn();
                                }
                            }
                            $result = queue_application_deployment(
                                application: $application,
                                pull_request_id: $pull_request_id,
                                deployment_uuid: $deployment_uuid,
                                force_rebuild: false,
                                commit: $commit ?? 'HEAD',
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

            return $this->manualWebhookResponse($return_payloads, $failure_key, $failure_attempt);
        } catch (InvalidWebhookPayloadException $e) {
            return response($e->getMessage());
        } catch (Exception $e) {
            return handleError($e);
        }
    }

    /**
     * Read and validate the pull_request payload fields that the handlers use.
     *
     * @return array{action: ?string, pull_request_id: int, pull_request_html_url: ?string, commit: ?string, skip_deploy: bool}
     *
     * @throws InvalidWebhookPayloadException When a field has a wrong type or format.
     */
    private function readPullRequestPayload(mixed $payload): array
    {
        return [
            'action' => $this->webhookPayloadString($payload, 'action'),
            'pull_request_id' => $this->webhookPullRequestId($payload, 'number'),
            'pull_request_html_url' => $this->webhookPayloadUrl($payload, 'pull_request.html_url'),
            // Gitea sends the head commit in pull_request.head.sha; newer versions also send it as after.
            'commit' => $this->webhookCommitSha($payload, 'pull_request.head.sha') ?? $this->webhookCommitSha($payload, 'after'),
            'skip_deploy' => self::shouldSkipDeployAny([$this->webhookPayloadString($payload, 'pull_request.title')]),
        ];
    }
}
