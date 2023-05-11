<?php

use App\Jobs\DeployApplicationJob;
use App\Models\Application;
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
        return generalErrorHandler($e);
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
        return redirect()->route('source.github.show', ['github_app_uuid' => $github_app->uuid]);
    } catch (\Exception $e) {
        return generalErrorHandler($e);
    }
});
Route::post('/source/github/events', function () {
    try {
        $x_github_delivery = request()->header('X-GitHub-Delivery');
        $x_github_event = Str::lower(request()->header('X-GitHub-Event'));
        $x_github_hook_installation_target_id = request()->header('X-GitHub-Hook-Installation-Target-Id');
        $x_hub_signature_256 = request()->header('X-Hub-Signature-256');
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
        // TODO: Verify signature
        // $webhook_secret = data_get($github_app, 'webhook_secret');
        // $key = hash('sha256', $webhook_secret, true);
        // $hmac = hash_hmac('sha256', request()->getContent(), $key);
        // if (!hash_equals($hmac, $x_hub_signature_256)) {
        //     return response('not cool');
        // }

        if ($x_github_event === 'push') {
            $id = data_get($payload, 'repository.id');
            $branch = data_get($payload, 'ref');
            if (Str::isMatch('/refs\/heads\/*/', $branch)) {
                $branch = Str::after($branch, 'refs/heads/');
            }
        }
        if ($x_github_event === 'pull_request') {
            $id = data_get($payload, 'pull_request.base.repo.id');
            $branch = data_get($payload, 'pull_request.base.ref');
        }
        if (!$id || !$branch) {
            return response('not cool');
        }
        $applications = Application::where('repository_project_id', $id)->where('git_branch', $branch)->get();
        foreach ($applications as $application) {
            if ($application->isDeployable()) {
                $deployment_uuid = new Cuid2(7);
                dispatch(new DeployApplicationJob(
                    deployment_uuid: $deployment_uuid,
                    application_uuid: $application->uuid,
                    force_rebuild: false,
                ));
            }
        }
    } catch (\Exception $e) {
        return generalErrorHandler($e);
    }
});
