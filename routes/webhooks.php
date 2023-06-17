<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\PrivateKey;
use App\Models\GithubApp;
use App\Models\GithubEventsApplications;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Visus\Cuid2\Cuid2;

Route::get('/source/github/redirect', function () {
    try {
        $code = request()->get('code');
        $state = request()->get('state');
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
            'name' => $slug,
            'private_key' => $private_key,
            'team_id' => $github_app->team_id
        ]);
        $github_app->app_id = $id;
        $github_app->client_id = $client_id;
        $github_app->client_secret = $client_secret;
        $github_app->webhook_secret = $webhook_secret;
        $github_app->private_key_id = $private_key->id;
        $github_app->save();
        return redirect()->route('source.github.show', ['github_app_uuid' => $github_app->uuid]);
    } catch (\Exception $e) {
        return general_error_handler(err: $e);
    }
});

Route::get('/source/github/install', function () {
    try {
        $installation_id = request()->get('installation_id');
        $source = request()->get('source');
        $setup_action = request()->get('setup_action');
        $github_app = GithubApp::where('uuid', $source)->firstOrFail();
        if ($setup_action === 'install') {
            $github_app->installation_id = $installation_id;
            $github_app->save();
        }
        // Must check the slug in case the user changes it in the GitHub App settings
        $token = generate_github_jwt_token($github_app);
        $response = Http::github($github_app->api_url, $token)->get('/app');
        $slug = data_get($response->json(), 'slug');
        $github_app->name = $slug;
        $github_app->save();

        return redirect()->route('source.github.show', ['github_app_uuid' => $github_app->uuid]);
    } catch (\Exception $e) {
        return general_error_handler(err: $e);
    }
});
Route::post('/source/github/events', function () {
    try {
        $x_github_delivery = request()->header('X-GitHub-Delivery');
        $x_github_event = Str::lower(request()->header('X-GitHub-Event'));
        $x_github_hook_installation_target_id = request()->header('X-GitHub-Hook-Installation-Target-Id');
        $x_hub_signature_256 = Str::after(request()->header('X-Hub-Signature-256'), 'sha256=');
        $payload = request()->collect();
        if ($x_github_event === 'ping') {
            // Just pong
            return response('pong');
        }
        if ($x_github_event === 'installation') {
            // Installation handled by setup redirect url. Repositories queried on-demand.
            return response('cool');
        }
        $github_app = GithubApp::where('app_id', $x_github_hook_installation_target_id)->firstOrFail();

        $webhook_secret = data_get($github_app, 'webhook_secret');
        $hmac = hash_hmac('sha256', request()->getContent(), $webhook_secret);
        ray($hmac, $x_hub_signature_256)->blue();
        if (config('app.env') !== 'local') {
            if (!hash_equals($x_hub_signature_256, $hmac)) {
                return response('not cool');
            }
        }

        if ($x_github_event === 'push') {
            $id = data_get($payload, 'repository.id');
            $branch = data_get($payload, 'ref');
            if (Str::isMatch('/refs\/heads\/*/', $branch)) {
                $branch = Str::after($branch, 'refs/heads/');
            }
            ray('Webhook GitHub Push Event: ' . $id . ' with branch: ' . $branch);
        }
        if ($x_github_event === 'pull_request') {
            $action = data_get($payload, 'action');
            $id = data_get($payload, 'repository.id');
            $pull_request_id = data_get($payload, 'number');
            $pull_request_html_url = data_get($payload, 'pull_request.html_url');
            $branch = data_get($payload, 'pull_request.head.ref');
            $base_branch = data_get($payload, 'pull_request.base.ref');
            ray('Webhook GitHub Pull Request Event: ' . $id . ' with branch: ' . $branch . ' and base branch: ' . $base_branch . ' and pull request id: ' . $pull_request_id);
        }
        if (!$id || !$branch) {
            return response('Nothing to do. No id or branch found.');
        }
        $applications = Application::where('repository_project_id', $id)->whereRelation('source', 'is_public', false);
        if ($x_github_event === 'push') {
            $applications = $applications->where('git_branch', $branch)->get();
        }
        if ($x_github_event === 'pull_request') {
            $applications = $applications->where('git_branch', $base_branch)->get();
        }
        if ($applications->isEmpty()) {
            return response('Nothing to do. No applications found.');
        }
        foreach ($applications as $application) {
            if ($x_github_event === 'push') {
                if ($application->isDeployable()) {
                    ray('Deploying ' . $application->name . ' with branch ' . $branch);
                    $deployment_uuid = new Cuid2(7);
                    queue_application_deployment(
                        application_id: $application->id,
                        deployment_uuid: $deployment_uuid,
                        force_rebuild: false,
                        is_webhook: true
                    );
                } else {
                    ray('Deployments disabled for ' . $application->name);
                }
            }
            if ($x_github_event === 'pull_request') {
                if ($action === 'opened' || $action === 'synchronize' || $action === 'reopened') {
                    if ($application->isPRDeployable()) {
                        $deployment_uuid = new Cuid2(7);
                        $found = ApplicationPreview::where('application_id', $application->id)->where('pull_request_id', $pull_request_id)->first();
                        if (!$found) {
                            ApplicationPreview::create([
                                'application_id' => $application->id,
                                'pull_request_id' => $pull_request_id,
                                'pull_request_html_url' => $pull_request_html_url
                            ]);
                        }
                        queue_application_deployment(
                            application_id: $application->id,
                            pull_request_id: $pull_request_id,
                            deployment_uuid: $deployment_uuid,
                            force_rebuild: false,
                            is_webhook: true
                        );
                        ray('Deploying preview for ' . $application->name . ' with branch ' . $branch . ' and base branch ' . $base_branch . ' and pull request id ' . $pull_request_id);
                        return response('Preview Deployment queued.');
                    } else {
                        ray('Preview deployments disabled for ' . $application->name);
                        return response('Nothing to do. Preview Deployments disabled.');
                    }
                }
                if ($action === 'closed') {
                    $found = ApplicationPreview::where('application_id', $application->id)->where('pull_request_id', $pull_request_id)->first();
                    if ($found) {
                        $found->delete();
                        $container_name = generate_container_name($application->uuid, $pull_request_id);
                        ray('Stopping container: ' . $container_name);
                        remote_process(["docker rm -f $container_name"], $application->destination->server);
                        return response('Preview Deployment closed.');
                    }
                    return response('Nothing to do. No Preview Deployment found');
                }
            }
        }
    } catch (\Exception $e) {
        return general_error_handler(err: $e);
    }
});
