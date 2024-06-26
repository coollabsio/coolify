<?php

use App\Http\Controllers\Api\Applications;
use App\Http\Controllers\Api\Deploy;
use App\Http\Controllers\Api\EnvironmentVariables;
use App\Http\Controllers\Api\Resources;
use App\Http\Controllers\Api\Server;
use App\Http\Controllers\Api\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return 'OK';
});
Route::post('/feedback', function (Request $request) {
    $content = $request->input('content');
    $webhook_url = config('coolify.feedback_discord_webhook');
    if ($webhook_url) {
        Http::post($webhook_url, [
            'content' => $content,
        ]);
    }

    return response()->json(['message' => 'Feedback sent.'], 200);
});

Route::group([
    'middleware' => ['auth:sanctum'],
    'prefix' => 'v1',
], function () {
    Route::get('/version', function () {
        return response(config('version'));
    });
    Route::match(['get', 'post'], '/deploy', [Deploy::class, 'deploy']);
    Route::get('/deployments', [Deploy::class, 'deployments']);
    Route::get('/deployments/{uuid}', [Deploy::class, 'deployment_by_uuid']);

    Route::get('/servers', [Server::class, 'servers']);
    Route::get('/servers/{uuid}', [Server::class, 'server_by_uuid']);
    Route::get('/servers/domains', [Server::class, 'get_domains_by_server']);

    Route::get('/resources', [Resources::class, 'resources']);

    Route::get('/applications', [Applications::class, 'applications']);

    Route::get('/applications/{uuid}', [Applications::class, 'application_by_uuid']);
    Route::patch('/applications/{uuid}', [Applications::class, 'update_by_uuid']);
    Route::delete('/applications/{uuid}', [Applications::class, 'delete_by_uuid']);

    Route::get('/applications/{uuid}/envs', [Applications::class, 'envs_by_uuid']);
    Route::post('/applications/{uuid}/envs', [Applications::class, 'create_env']);
    Route::patch('/applications/{uuid}/envs', [Applications::class, 'update_env_by_uuid']);
    Route::delete('/applications/{uuid}/envs/{env_uuid}', [Applications::class, 'delete_env_by_uuid']);

    Route::delete('/envs/{env_uuid}', [EnvironmentVariables::class, 'delete_env_by_uuid']);

    Route::match(['get', 'post'], '/applications/{uuid}/action/deploy', [Applications::class, 'action_deploy']);
    Route::match(['get', 'post'], '/applications/{uuid}/action/restart', [Applications::class, 'action_restart']);
    Route::match(['get', 'post'], '/applications/{uuid}/action/stop', [Applications::class, 'action_stop']);

    Route::get('/teams', [Team::class, 'teams']);
    Route::get('/teams/current', [Team::class, 'current_team']);
    Route::get('/teams/current/members', [Team::class, 'current_team_members']);
    Route::get('/teams/{id}', [Team::class, 'team_by_id']);
    Route::get('/teams/{id}/members', [Team::class, 'members_by_id']);

    // Route::get('/projects', [Project::class, 'projects']);
    //Route::get('/project/{uuid}', [Project::class, 'project_by_uuid']);
    //Route::get('/project/{uuid}/{environment_name}', [Project::class, 'environment_details']);
});

Route::any('/{any}', function () {
    return response()->json(['error' => 'Not found.'], 404);
})->where('any', '.*');

// Route::middleware(['throttle:5'])->group(function () {
//     Route::get('/unsubscribe/{token}', function () {
//         try {
//             $token = request()->token;
//             $email = decrypt($token);
//             if (!User::whereEmail($email)->exists()) {
//                 return redirect(RouteServiceProvider::HOME);
//             }
//             if (User::whereEmail($email)->first()->marketing_emails === false) {
//                 return 'You have already unsubscribed from marketing emails.';
//             }
//             User::whereEmail($email)->update(['marketing_emails' => false]);
//             return 'You have been unsubscribed from marketing emails.';
//         } catch (\Throwable $e) {
//             return 'Something went wrong. Please try again or contact support.';
//         }
//     })->name('unsubscribe.marketing.emails');
// });
