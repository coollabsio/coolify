<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Webhook\Concerns\DetectsSkipDeployCommits;
use App\Http\Controllers\Webhook\Concerns\MatchesManualWebhookApplications;
use App\Jobs\GithubAppPermissionJob;
use App\Jobs\ProcessGithubPullRequestWebhook;
use App\Models\Application;
use App\Models\GithubApp;
use App\Models\PrivateKey;
use Exception;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Visus\Cuid2\Cuid2;

class Github extends Controller
{
    use DetectsSkipDeployCommits;
    use MatchesManualWebhookApplications;

    public function manual(Request $request)
    {
        try {
            $return_payloads = collect([]);
            $x_github_delivery = request()->header('X-GitHub-Delivery');
            $x_github_event = Str::lower($request->header('X-GitHub-Event'));
            $x_hub_signature_256 = Str::after($request->header('X-Hub-Signature-256'), 'sha256=');
            $content_type = $request->header('Content-Type');
            $payload = $request->collect();
            if ($x_github_event === 'ping') {
                // Just pong
                return response('pong');
            }

            if ($content_type !== 'application/json') {
                $payload = json_decode(data_get($payload, 'payload'), true);
            }
            if ($x_github_event === 'push') {
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
            if ($x_github_event === 'pull_request') {
                $action = data_get($payload, 'action');
                $full_name = data_get($payload, 'repository.full_name');
                $pull_request_id = data_get($payload, 'number');
                $pull_request_html_url = data_get($payload, 'pull_request.html_url');
                $pull_request_title = data_get($payload, 'pull_request.title');
                $branch = data_get($payload, 'pull_request.head.ref');
                $base_branch = data_get($payload, 'pull_request.base.ref');
                $before_sha = data_get($payload, 'before');
                $after_sha = data_get($payload, 'after', data_get($payload, 'pull_request.head.sha'));
                $author_association = data_get($payload, 'pull_request.author_association');
                $is_fork_pull_request = $this->isForkPullRequest($payload);
            }
            if (! in_array($x_github_event, ['push', 'pull_request'])) {
                return response("Nothing to do. Event '$x_github_event' is not supported.");
            }
            if (! $branch) {
                return response('Nothing to do. No branch found in the request.');
            }
            $full_name = $this->manualWebhookRepositoryFullName($full_name);
            if ($full_name === null) {
                return response('Nothing to do. Invalid repository.');
            }
            $applications = Application::query();
            if ($x_github_event === 'push') {
                $applications = $this->manualWebhookApplications($applications->where('git_branch', $branch), $full_name);
                if ($applications->isEmpty()) {
                    return response("Nothing to do. No applications found with deploy key set, branch is '$branch' and Git Repository name has $full_name.");
                }
            }
            if ($x_github_event === 'pull_request') {
                $applications = $this->manualWebhookApplications($applications->where('git_branch', $base_branch), $full_name);
                if ($applications->isEmpty()) {
                    return response("Nothing to do. No applications found for repo $full_name and branch '$base_branch'.");
                }
            }
            $applicationsByServer = $applications->groupBy(function ($app) {
                return $app->destination->server_id;
            });

            foreach ($applicationsByServer as $serverId => $serverApplications) {
                foreach ($serverApplications as $application) {
                    $webhook_secret = data_get($application, 'manual_webhook_secret_github');
                    if (empty($webhook_secret)) {
                        auditLogWebhookFailure('github', 'webhook_secret_missing', [
                            'application_uuid' => $application->uuid,
                            'application_name' => $application->name,
                            'repository' => $full_name ?? null,
                            'mode' => 'manual',
                        ]);
                        $return_payloads->push($this->unauthenticatedManualWebhookFailurePayload());

                        continue;
                    }
                    $hmac = hash_hmac('sha256', $request->getContent(), $webhook_secret);
                    if (! hash_equals($x_hub_signature_256, $hmac) && ! isDev()) {
                        auditLogWebhookFailure('github', 'invalid_signature', [
                            'application_uuid' => $application->uuid,
                            'application_name' => $application->name,
                            'repository' => $full_name ?? null,
                            'mode' => 'manual',
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
                    if ($x_github_event === 'push') {
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
                                        'provider' => 'github',
                                        'mode' => 'manual',
                                        'application_uuid' => $application->uuid,
                                        'application_name' => $application->name,
                                        'deployment_uuid' => $result['deployment_uuid'],
                                        'commit' => data_get($payload, 'after'),
                                        'repository' => $full_name ?? null,
                                    ]);
                                    $return_payloads->push([
                                        'application' => $application->name,
                                        'status' => 'success',
                                        'message' => 'Deployment queued.',
                                        'application_uuid' => $application->uuid,
                                        'application_name' => $application->name,
                                        'deployment_uuid' => $result['deployment_uuid'],
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
                    if ($x_github_event === 'pull_request') {
                        // Check if PR deployments are enabled (but allow 'closed' action to cleanup)
                        if (! $application->isPRDeployable() && $action !== 'closed') {
                            $return_payloads->push([
                                'application' => $application->name,
                                'status' => 'failed',
                                'message' => 'Preview deployments disabled.',
                            ]);

                            continue;
                        }

                        ProcessGithubPullRequestWebhook::dispatch(
                            applicationId: $application->id,
                            githubAppId: null,
                            action: $action,
                            pullRequestId: $pull_request_id,
                            pullRequestHtmlUrl: $pull_request_html_url,
                            pullRequestTitle: $pull_request_title ?? null,
                            beforeSha: $before_sha,
                            afterSha: $after_sha,
                            commitSha: data_get($payload, 'pull_request.head.sha', 'HEAD'),
                            authorAssociation: $author_association,
                            fullName: $full_name,
                            isForkPullRequest: $is_fork_pull_request ?? false,
                        );

                        $return_payloads->push([
                            'application' => $application->name,
                            'status' => 'queued',
                            'message' => 'PR webhook received, processing queued.',
                        ]);
                    }
                }
            }

            return response($return_payloads);
        } catch (Exception $e) {
            return handleError($e);
        }
    }

    public function normal(Request $request)
    {
        try {
            $return_payloads = collect([]);
            $id = null;
            $x_github_delivery = $request->header('X-GitHub-Delivery');
            $x_github_event = Str::lower($request->header('X-GitHub-Event'));
            $x_github_hook_installation_target_id = $request->header('X-GitHub-Hook-Installation-Target-Id');
            $x_hub_signature_256 = Str::after($request->header('X-Hub-Signature-256'), 'sha256=');
            $payload = $request->collect();
            if ($x_github_event === 'ping') {
                // Just pong
                return response('pong');
            }
            $github_app = GithubApp::where('app_id', $x_github_hook_installation_target_id)->first();
            if (is_null($github_app)) {
                return response('Nothing to do. No GitHub App found.');
            }
            $webhook_secret = data_get($github_app, 'webhook_secret');
            $hmac = hash_hmac('sha256', $request->getContent(), $webhook_secret);
            if (config('app.env') !== 'local') {
                if (! hash_equals($x_hub_signature_256, $hmac)) {
                    auditLogWebhookFailure('github', 'invalid_signature', [
                        'mode' => 'app',
                        'github_app_id' => $github_app->id,
                        'github_app_name' => $github_app->name,
                        'installation_target_id' => $x_github_hook_installation_target_id,
                    ]);

                    return response('Invalid signature.');
                }
            }
            if ($x_github_event === 'installation' || $x_github_event === 'installation_repositories') {
                // Installation handled by setup redirect url. Repositories queried on-demand.
                $action = data_get($payload, 'action');
                if ($action === 'new_permissions_accepted') {
                    GithubAppPermissionJob::dispatch($github_app);
                }

                return response('cool');
            }
            if ($x_github_event === 'push') {
                $id = data_get($payload, 'repository.id');
                $branch = data_get($payload, 'ref');
                if (Str::isMatch('/refs\/heads\/*/', $branch)) {
                    $branch = Str::after($branch, 'refs/heads/');
                }
                $added_files = data_get($payload, 'commits.*.added');
                $removed_files = data_get($payload, 'commits.*.removed');
                $modified_files = data_get($payload, 'commits.*.modified');
                $changed_files = collect($added_files)->concat($removed_files)->concat($modified_files)->unique()->flatten();
                $skip_deploy_commits = self::shouldSkipDeploy(data_get($payload, 'commits.*.message', []));
            }
            if ($x_github_event === 'pull_request') {
                $action = data_get($payload, 'action');
                $id = data_get($payload, 'repository.id');
                $pull_request_id = data_get($payload, 'number');
                $pull_request_html_url = data_get($payload, 'pull_request.html_url');
                $pull_request_title = data_get($payload, 'pull_request.title');
                $branch = data_get($payload, 'pull_request.head.ref');
                $base_branch = data_get($payload, 'pull_request.base.ref');
                $before_sha = data_get($payload, 'before');
                $after_sha = data_get($payload, 'after', data_get($payload, 'pull_request.head.sha'));
                $author_association = data_get($payload, 'pull_request.author_association');
                $is_fork_pull_request = $this->isForkPullRequest($payload);
            }
            if (! in_array($x_github_event, ['push', 'pull_request'])) {
                return response("Nothing to do. Event '$x_github_event' is not supported.");
            }
            if (! $id || ! $branch) {
                return response('Nothing to do. No id or branch found.');
            }
            $applications = Application::where('repository_project_id', $id)
                ->where('source_id', $github_app->id)
                ->whereRelation('source', 'is_public', false);
            if ($x_github_event === 'push') {
                $applications = $applications->where('git_branch', $branch)->get();
                if ($applications->isEmpty()) {
                    return response("Nothing to do. No applications found with branch '$branch'.");
                }
            }
            if ($x_github_event === 'pull_request') {
                $applications = $applications->where('git_branch', $base_branch)->get();
                if ($applications->isEmpty()) {
                    return response("Nothing to do. No applications found with branch '$base_branch'.");
                }
            }
            $applicationsByServer = $applications->groupBy(function ($app) {
                return $app->destination->server_id;
            });

            foreach ($applicationsByServer as $serverId => $serverApplications) {
                foreach ($serverApplications as $application) {
                    $isFunctional = $application->destination->server->isFunctional();
                    if (! $isFunctional) {
                        $return_payloads->push([
                            'status' => 'failed',
                            'message' => 'Server is not functional.',
                            'application_uuid' => $application->uuid,
                            'application_name' => $application->name,
                        ]);

                        continue;
                    }
                    if ($x_github_event === 'push') {
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
                                    commit: data_get($payload, 'after', 'HEAD'),
                                    force_rebuild: false,
                                    is_webhook: true,
                                );
                                if ($result['status'] === 'queue_full') {
                                    return response($result['message'], 429)->header('Retry-After', 60);
                                }
                                if ($result['status'] !== 'skipped' && ! empty($result['deployment_uuid'])) {
                                    auditLog('webhook.deployment.queued', [
                                        'provider' => 'github',
                                        'mode' => 'app',
                                        'application_uuid' => $application->uuid,
                                        'application_name' => $application->name,
                                        'deployment_uuid' => $result['deployment_uuid'],
                                        'commit' => data_get($payload, 'after'),
                                        'github_app_id' => $github_app->id,
                                    ]);
                                }
                                $return_payloads->push([
                                    'status' => $result['status'],
                                    'message' => $result['message'],
                                    'application_uuid' => $application->uuid,
                                    'application_name' => $application->name,
                                    'deployment_uuid' => $result['deployment_uuid'] ?? null,
                                ]);
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
                    if ($x_github_event === 'pull_request') {
                        // Check if PR deployments are enabled (but allow 'closed' action to cleanup)
                        if (! $application->isPRDeployable() && $action !== 'closed') {
                            $return_payloads->push([
                                'application' => $application->name,
                                'status' => 'failed',
                                'message' => 'Preview deployments disabled.',
                            ]);

                            continue;
                        }

                        $full_name = data_get($payload, 'repository.full_name');

                        ProcessGithubPullRequestWebhook::dispatch(
                            applicationId: $application->id,
                            githubAppId: $github_app->id,
                            action: $action,
                            pullRequestId: $pull_request_id,
                            pullRequestHtmlUrl: $pull_request_html_url,
                            pullRequestTitle: $pull_request_title ?? null,
                            beforeSha: $before_sha,
                            afterSha: $after_sha,
                            commitSha: data_get($payload, 'pull_request.head.sha', 'HEAD'),
                            authorAssociation: $author_association,
                            fullName: $full_name,
                            isForkPullRequest: $is_fork_pull_request ?? false,
                        );

                        $return_payloads->push([
                            'application' => $application->name,
                            'status' => 'queued',
                            'message' => 'PR webhook received, processing queued.',
                        ]);
                    }
                }
            }

            return response($return_payloads);
        } catch (Exception $e) {
            return handleError($e);
        }
    }

    /**
     * Determine whether a pull_request webhook payload originates from a fork.
     *
     * GitHub's `author_association` is not a reliable trust signal (it grants
     * CONTRIBUTOR to anyone who has merely opened an issue/PR before), so fork
     * detection is gated on whether the PR crosses repository boundaries.
     *
     * The repository id comparison is the canonical signal; the `head.repo.fork`
     * flag and a case-insensitive full_name comparison are fallbacks for payloads
     * where the ids are unavailable (e.g. a deleted head repository).
     */
    private function isForkPullRequest(mixed $payload): bool
    {
        $headRepoId = data_get($payload, 'pull_request.head.repo.id');
        $baseRepoId = data_get($payload, 'pull_request.base.repo.id');

        if ($headRepoId !== null && $baseRepoId !== null) {
            return (string) $headRepoId !== (string) $baseRepoId;
        }

        if (data_get($payload, 'pull_request.head.repo.fork') === true) {
            return true;
        }

        $headRepoFullName = data_get($payload, 'pull_request.head.repo.full_name');
        $baseRepoFullName = data_get($payload, 'pull_request.base.repo.full_name');

        if (is_string($headRepoFullName) && is_string($baseRepoFullName)) {
            return Str::lower($headRepoFullName) !== Str::lower($baseRepoFullName);
        }

        return false;
    }

    public function redirect(Request $request)
    {
        $code = (string) $request->query('code', '');
        abort_if(blank($code), 422, 'Missing GitHub App manifest code.');

        $github_app = $this->consumeGithubAppSetupState(
            request: $request,
            state: (string) $request->query('state', ''),
            action: 'manifest',
        );

        abort_if($this->githubAppHasManifestCredentials($github_app), 403, 'GitHub App credentials are already configured.');

        $api_url = data_get($github_app, 'api_url');
        $data = Http::withBody(null)
            ->accept('application/vnd.github+json')
            ->timeout(10)
            ->connectTimeout(5)
            ->post("$api_url/app-manifests/$code/conversions")
            ->throw()
            ->json();

        $id = data_get($data, 'id');
        $slug = data_get($data, 'slug');
        $client_id = data_get($data, 'client_id');
        $client_secret = data_get($data, 'client_secret');
        $private_key = data_get($data, 'pem');
        $webhook_secret = data_get($data, 'webhook_secret');

        abort_if(blank($id) || blank($slug) || blank($client_id) || blank($client_secret) || blank($private_key) || blank($webhook_secret), 422, 'GitHub App manifest conversion response is incomplete.');

        $private_key = PrivateKey::create([
            'name' => "github-app-{$slug}",
            'private_key' => $private_key,
            'team_id' => $github_app->team_id,
            'is_git_related' => true,
        ]);
        $github_app->name = $slug;
        $github_app->app_id = $id;
        $github_app->client_id = $client_id;
        $github_app->client_secret = $client_secret;
        $github_app->webhook_secret = $webhook_secret;
        $github_app->private_key_id = $private_key->id;
        $github_app->save();

        return redirect()->route('source.github.show', ['github_app_uuid' => $github_app->uuid]);
    }

    public function install(Request $request)
    {
        $setup_action = (string) $request->query('setup_action', '');
        abort_unless(in_array($setup_action, ['install', 'update'], true), 422, 'Invalid GitHub App setup action.');

        $installation_id = (string) $request->query('installation_id', '');
        abort_unless(ctype_digit($installation_id), 422, 'Missing GitHub App installation id.');

        if ($setup_action === 'update') {
            return $this->redirectAfterGithubAppInstallationUpdate($installation_id);
        }

        $github_app = $this->consumeGithubAppSetupState(
            request: $request,
            state: (string) $request->query('state', ''),
            action: 'install',
        );

        abort_unless(
            $this->githubInstallationBelongsToApp($github_app, $installation_id),
            403,
            'GitHub App installation could not be verified.'
        );

        $github_app->installation_id = $installation_id;
        $github_app->save();

        return redirect()->route('source.github.show', ['github_app_uuid' => $github_app->uuid]);
    }

    private function redirectAfterGithubAppInstallationUpdate(string $installation_id): RedirectResponse
    {
        $github_app = GithubApp::ownedByCurrentTeam()
            ->where('installation_id', $installation_id)
            ->first();

        if ($github_app) {
            return redirect()->route('source.github.show', ['github_app_uuid' => $github_app->uuid]);
        }

        return redirect()->route('source.all');
    }

    /**
     * Verify that the given installation id actually belongs to this GitHub App.
     *
     * The installation id arrives as an untrusted query parameter on an
     * unauthenticated-reachable GET callback, so it must be confirmed against
     * the GitHub API using the App's own credentials before it is persisted.
     */
    private function githubInstallationBelongsToApp(GithubApp $github_app, string $installation_id): bool
    {
        if (blank($github_app->app_id) || blank($github_app->privateKey?->private_key)) {
            return false;
        }

        try {
            $jwt = generateGithubJwt($github_app);
            $response = Http::withHeaders([
                'Authorization' => "Bearer $jwt",
                'Accept' => 'application/vnd.github+json',
            ])
                ->timeout(10)
                ->connectTimeout(5)
                ->get("{$github_app->api_url}/app/installations/{$installation_id}");

            return $response->successful()
                && (string) data_get($response->json(), 'app_id') === (string) $github_app->app_id;
        } catch (\Throwable) {
            return false;
        }
    }

    private function consumeGithubAppSetupState(Request $request, string $state, string $action): GithubApp
    {
        if (blank($state)) {
            $this->rejectInvalidGithubAppSetupState($request);
        }

        $payload = Cache::pull($this->githubAppSetupStateCacheKey($state));
        if (! is_array($payload) || data_get($payload, 'action') !== $action) {
            $this->rejectInvalidGithubAppSetupState($request);
        }

        $team_id = $request->user()?->currentTeam()?->id;
        abort_unless(! is_null($team_id) && (int) data_get($payload, 'team_id') === $team_id, 403);

        return GithubApp::whereKey(data_get($payload, 'github_app_id'))
            ->where('team_id', data_get($payload, 'team_id'))
            ->firstOrFail();
    }

    private function rejectInvalidGithubAppSetupState(Request $request): never
    {
        if ($request->expectsJson()) {
            abort(404);
        }

        throw new HttpResponseException(
            redirect()
                ->route('source.all')
        );
    }

    private function githubAppSetupStateCacheKey(string $state): string
    {
        return 'github-app-setup-state:'.hash('sha256', $state);
    }

    private function githubAppHasManifestCredentials(GithubApp $github_app): bool
    {
        return filled($github_app->app_id)
            || filled($github_app->client_id)
            || filled($github_app->client_secret)
            || filled($github_app->webhook_secret)
            || filled($github_app->private_key_id);
    }
}
