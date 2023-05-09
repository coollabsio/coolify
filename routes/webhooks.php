<?php

use App\Models\PrivateKey;
use App\Models\GithubApp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

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
