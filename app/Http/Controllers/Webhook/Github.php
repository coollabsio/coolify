<?php

namespace App\Http\Controllers\Webhook;

use App\Enums\ProcessStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ApplicationPullRequestUpdateJob;
use App\Jobs\GithubAppPermissionJob;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\GithubApp;
use App\Models\PrivateKey;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Visus\Cuid2\Cuid2;

class Github extends Controller
{
    public function manual(Request $request)
    {
        try {
            $return_payloads = collect([]);
            $x_github_delivery = request()->header('X-GitHub-Delivery');
            if (app()->isDownForMaintenance()) {
                $epoch = now()->valueOf();
                $files = Storage::disk('webhooks-during-maintenance')->files();
                $github_delivery_found = collect($files)->filter(function ($file) use ($x_github_delivery) {
                    return Str::contains($file, $x_github_delivery);
                })->first();
                if ($github_delivery_found) {
                    return;
                }
                $data = [
                    'attributes' => $request->attributes->all(),
                    'request' => $request->request->all(),
                    'query' => $request->query->all(),
                    'server' => $request->server->all(),
                    'files' => $request->files->all(),
                    'cookies' => $request->cookies->all(),
                    'headers' => $request->headers->all(),
                    'content' => $request->getContent(),
                ];
                $json = json_encode($data);
                Storage::disk('webhooks-during-maintenance')->put("{$epoch}_Github::manual_{$x_github_delivery}", $json);

                return;
            }
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
            }
            if ($x_github_event === 'pull_request') {
                $action = data_get($payload, 'action');
                $full_name = data_get($payload, 'repository.full_name');
                $pull_request_id = data_get($payload, 'number');
                $pull_request_html_url = data_get($payload, 'pull_request.html_url');
                $branch = data_get($payload, 'pull_request.head.ref');
                $base_branch = data_get($payload, 'pull_request.base.ref');
            }
            if (! $branch) {
                return response('Nothing to do. No branch found in the request.');
            }
            $applications = Application::where('git_repository', 'like', "%$full_name%");
            if ($x_github_event === 'push') {
                $applications = $applications->where('git_branch', $branch)->get();
                if ($applications->isEmpty()) {
                    return response("Nothing to do. No applications found with deploy key set, branch is '$branch' and Git Repository name has $full_name.");
                }
            }
            if ($x_github_event === 'pull_request') {
                $applications = $applications->where('git_branch', $base_branch)->get();
                if ($applications->isEmpty()) {
                    return response("Nothing to do. No applications found with branch '$base_branch'.");
                }
            }
            foreach ($applications as $application) {
                $webhook_secret = data_get($application, 'manual_webhook_secret_github');
                $hmac = hash_hmac('sha256', $request->getContent(), $webhook_secret);
                if (! hash_equals($x_hub_signature_256, $hmac) && ! isDev()) {
                    $return_payloads->push([
                        'application' => $application->name,
                        'status' => 'failed',
                        'message' => 'Invalid signature.',
                    ]);

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
                        if ($is_watch_path_triggered || is_null($application->watch_paths)) {
                            $deployment_uuid = new Cuid2;
                            queue_application_deployment(
                                application: $application,
                                deployment_uuid: $deployment_uuid,
                                force_rebuild: false,
                                commit: data_get($payload, 'after', 'HEAD'),
                                is_webhook: true,
                            );
                            $return_payloads->push([
                                'status' => 'success',
                                'message' => 'Deployment queued.',
                                'application_uuid' => $application->uuid,
                                'application_name' => $application->name,
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
                    if ($action === 'opened' || $action === 'synchronize' || $action === 'reopened') {
                        if ($application->isPRDeployable()) {
                            $deployment_uuid = new Cuid2;
                            $found = ApplicationPreview::where('application_id', $application->id)->where('pull_request_id', $pull_request_id)->first();
                            if (! $found) {
                                if ($application->build_pack === 'dockercompose') {
                                    $pr_app = ApplicationPreview::create([
                                        'git_type' => 'github',
                                        'application_id' => $application->id,
                                        'pull_request_id' => $pull_request_id,
                                        'pull_request_html_url' => $pull_request_html_url,
                                        'docker_compose_domains' => $application->docker_compose_domains,
                                    ]);
                                    $pr_app->generate_preview_fqdn_compose();
                                } else {
                                    ApplicationPreview::create([
                                        'git_type' => 'github',
                                        'application_id' => $application->id,
                                        'pull_request_id' => $pull_request_id,
                                        'pull_request_html_url' => $pull_request_html_url,
                                    ]);
                                }
                            }
                            queue_application_deployment(
                                application: $application,
                                pull_request_id: $pull_request_id,
                                deployment_uuid: $deployment_uuid,
                                force_rebuild: false,
                                commit: data_get($payload, 'head.sha', 'HEAD'),
                                is_webhook: true,
                                git_type: 'github'
                            );
                            $return_payloads->push([
                                'application' => $application->name,
                                'status' => 'success',
                                'message' => 'Preview deployment queued.',
                            ]);
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
                            $found->delete();
                            $container_name = generateApplicationContainerName($application, $pull_request_id);
                            // ray('Stopping container: ' . $container_name);
                            instant_remote_process(["docker rm -f $container_name"], $application->destination->server);
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

    public function normal(Request $request)
    {
        try {
            $return_payloads = collect([]);
            $id = null;
            $x_github_delivery = $request->header('X-GitHub-Delivery');
            if (app()->isDownForMaintenance()) {
                $epoch = now()->valueOf();
                $files = Storage::disk('webhooks-during-maintenance')->files();
                $github_delivery_found = collect($files)->filter(function ($file) use ($x_github_delivery) {
                    return Str::contains($file, $x_github_delivery);
                })->first();
                if ($github_delivery_found) {
                    return;
                }
                $data = [
                    'attributes' => $request->attributes->all(),
                    'request' => $request->request->all(),
                    'query' => $request->query->all(),
                    'server' => $request->server->all(),
                    'files' => $request->files->all(),
                    'cookies' => $request->cookies->all(),
                    'headers' => $request->headers->all(),
                    'content' => $request->getContent(),
                ];
                $json = json_encode($data);
                Storage::disk('webhooks-during-maintenance')->put("{$epoch}_Github::normal_{$x_github_delivery}", $json);

                return;
            }
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
            }
            if ($x_github_event === 'pull_request') {
                $action = data_get($payload, 'action');
                $id = data_get($payload, 'repository.id');
                $pull_request_id = data_get($payload, 'number');
                $pull_request_html_url = data_get($payload, 'pull_request.html_url');
                $branch = data_get($payload, 'pull_request.head.ref');
                $base_branch = data_get($payload, 'pull_request.base.ref');
            }
            if (! $id || ! $branch) {
                return response('Nothing to do. No id or branch found.');
            }
            $applications = Application::where('repository_project_id', $id)->whereRelation('source', 'is_public', false);
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
            foreach ($applications as $application) {
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
                        if ($is_watch_path_triggered || is_null($application->watch_paths)) {
                            $deployment_uuid = new Cuid2;
                            queue_application_deployment(
                                application: $application,
                                deployment_uuid: $deployment_uuid,
                                commit: data_get($payload, 'after', 'HEAD'),
                                force_rebuild: false,
                                is_webhook: true,
                            );
                            $return_payloads->push([
                                'status' => 'success',
                                'message' => 'Deployment queued.',
                                'application_uuid' => $application->uuid,
                                'application_name' => $application->name,
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
                    if ($action === 'opened' || $action === 'synchronize' || $action === 'reopened') {
                        if ($application->isPRDeployable()) {
                            $deployment_uuid = new Cuid2;
                            $found = ApplicationPreview::where('application_id', $application->id)->where('pull_request_id', $pull_request_id)->first();
                            if (! $found) {
                                ApplicationPreview::create([
                                    'git_type' => 'github',
                                    'application_id' => $application->id,
                                    'pull_request_id' => $pull_request_id,
                                    'pull_request_html_url' => $pull_request_html_url,
                                ]);
                            }
                            queue_application_deployment(
                                application: $application,
                                pull_request_id: $pull_request_id,
                                deployment_uuid: $deployment_uuid,
                                force_rebuild: false,
                                commit: data_get($payload, 'head.sha', 'HEAD'),
                                is_webhook: true,
                                git_type: 'github'
                            );
                            $return_payloads->push([
                                'application' => $application->name,
                                'status' => 'success',
                                'message' => 'Preview deployment queued.',
                            ]);
                        } else {
                            $return_payloads->push([
                                'application' => $application->name,
                                'status' => 'failed',
                                'message' => 'Preview deployments disabled.',
                            ]);
                        }
                    }
                    if ($action === 'closed' || $action === 'close') {
                        $found = ApplicationPreview::where('application_id', $application->id)->where('pull_request_id', $pull_request_id)->first();
                        if ($found) {
                            $containers = getCurrentApplicationContainerStatus($application->destination->server, $application->id, $pull_request_id);
                            if ($containers->isNotEmpty()) {
                                $containers->each(function ($container) use ($application) {
                                    $container_name = data_get($container, 'Names');
                                    instant_remote_process(["docker rm -f $container_name"], $application->destination->server);
                                });
                            }

                            ApplicationPullRequestUpdateJob::dispatchSync(application: $application, preview: $found, status: ProcessStatus::CLOSED);
                            $found->delete();

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

    public function redirect(Request $request)
    {
        try {
            $code = $request->get('code');
            $state = $request->get('state');
            $github_app = GithubApp::where('uuid', $state)->firstOrFail();
            $api_url = data_get($github_app, 'api_url');
            $data = Http::withBody(null)->accept('application/vnd.github+json')->post("$api_url/app-manifests/$code/conversions")->throw()->json();
            $id = data_get($data, 'id');
            $slug = data_get($data, 'slug');
            $client_id = data_get($data, 'client_id');
            $client_secret = data_get($data, 'client_secret');
            $private_key = data_get($data, 'pem');
            $webhook_secret = data_get($data, 'webhook_secret');
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
        } catch (Exception $e) {
            return handleError($e);
        }
    }

    public function install(Request $request)
    {
        try {
            $installation_id = $request->get('installation_id');
            if (app()->isDownForMaintenance()) {
                $epoch = now()->valueOf();
                $data = [
                    'attributes' => $request->attributes->all(),
                    'request' => $request->request->all(),
                    'query' => $request->query->all(),
                    'server' => $request->server->all(),
                    'files' => $request->files->all(),
                    'cookies' => $request->cookies->all(),
                    'headers' => $request->headers->all(),
                    'content' => $request->getContent(),
                ];
                $json = json_encode($data);
                Storage::disk('webhooks-during-maintenance')->put("{$epoch}_Github::install_{$installation_id}", $json);

                return;
            }
            $source = $request->get('source');
            $setup_action = $request->get('setup_action');
            $github_app = GithubApp::where('uuid', $source)->firstOrFail();
            if ($setup_action === 'install') {
                $github_app->installation_id = $installation_id;
                $github_app->save();
            }

            return redirect()->route('source.github.show', ['github_app_uuid' => $github_app->uuid]);
        } catch (Exception $e) {
            return handleError($e);
        }
    }
}
