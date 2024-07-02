<?php

use App\Http\Controllers\Api\ApplicationsController;
use App\Http\Controllers\Api\DatabasesController;
use App\Http\Controllers\Api\DeployController;
use App\Http\Controllers\Api\EnvironmentVariablesController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ResourcesController;
use App\Http\Controllers\Api\SecurityController;
use App\Http\Controllers\Api\ServersController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Middleware\ApiAllowed;
use App\Http\Middleware\OnlyRootApiToken;
use App\Http\Middleware\ReadOnlyApiToken;
use App\Models\InstanceSettings;
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

    return response()->json(['success' => true, 'message' => 'Feedback sent.'], 200);
});

Route::group([
    'middleware' => ['auth:sanctum', OnlyRootApiToken::class],
    'prefix' => 'v1',
], function () {
    Route::get('/enable', function () {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        if ($teamId !== '0') {
            return response()->json(['success' => false, 'message' => 'You are not allowed to enable the API.'], 403);
        }
        $settings = InstanceSettings::get();
        $settings->update(['is_api_enabled' => true]);

        return response()->json(['success' => true, 'message' => 'API enabled.'], 200);
    });
    Route::get('/disable', function () {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        if ($teamId !== '0') {
            return response()->json(['success' => false, 'message' => 'You are not allowed to disable the API.'], 403);
        }
        $settings = InstanceSettings::get();
        $settings->update(['is_api_enabled' => false]);

        return response()->json(['success' => true, 'message' => 'API disabled.'], 200);
    });

});
Route::group([
    'middleware' => ['auth:sanctum', ApiAllowed::class],
    'prefix' => 'v1',
], function () {
    Route::get('/version', function () {
        return response(config('version'));
    });

    Route::get('/teams', [TeamController::class, 'teams']);
    Route::get('/teams/current', [TeamController::class, 'current_team']);
    Route::get('/teams/current/members', [TeamController::class, 'current_team_members']);
    Route::get('/teams/{id}', [TeamController::class, 'team_by_id']);
    Route::get('/teams/{id}/members', [TeamController::class, 'members_by_id']);

    Route::get('/projects', [ProjectController::class, 'projects']);
    Route::get('/projects/{uuid}', [ProjectController::class, 'project_by_uuid']);
    Route::get('/projects/{uuid}/{environment_name}', [ProjectController::class, 'environment_details']);

    Route::get('/security/keys', [SecurityController::class, 'keys']);
    Route::post('/security/keys', [SecurityController::class, 'create_key'])->middleware([ReadOnlyApiToken::class]);

    Route::get('/security/keys/{uuid}', [SecurityController::class, 'key_by_uuid']);
    Route::patch('/security/keys/{uuid}', [SecurityController::class, 'update_key'])->middleware([ReadOnlyApiToken::class]);
    Route::delete('/security/keys/{uuid}', [SecurityController::class, 'delete_key'])->middleware([ReadOnlyApiToken::class]);

    Route::match(['get', 'post'], '/deploy', [DeployController::class, 'deploy'])->middleware([ReadOnlyApiToken::class]);

    Route::get('/deployments', [DeployController::class, 'deployments']);
    Route::get('/deployments/{uuid}', [DeployController::class, 'deployment_by_uuid']);

    Route::get('/servers', [ServersController::class, 'servers']);
    Route::get('/servers/{uuid}', [ServersController::class, 'server_by_uuid']);
    Route::get('/servers/{uuid}/domains', [ServersController::class, 'get_domains_by_server']);

    Route::get('/resources', [ResourcesController::class, 'resources']);

    Route::get('/applications', [ApplicationsController::class, 'applications']);
    Route::post('/applications', [ApplicationsController::class, 'create_application'])->middleware([ReadOnlyApiToken::class]);

    Route::get('/applications/{uuid}', [ApplicationsController::class, 'application_by_uuid']);
    Route::patch('/applications/{uuid}', [ApplicationsController::class, 'update_by_uuid'])->middleware([ReadOnlyApiToken::class]);
    Route::delete('/applications/{uuid}', [ApplicationsController::class, 'delete_by_uuid'])->middleware([ReadOnlyApiToken::class]);

    Route::get('/applications/{uuid}/envs', [ApplicationsController::class, 'envs_by_uuid']);
    Route::post('/applications/{uuid}/envs', [ApplicationsController::class, 'create_env'])->middleware([ReadOnlyApiToken::class]);
    Route::post('/applications/{uuid}/envs/bulk', [ApplicationsController::class, 'create_bulk_envs'])->middleware([ReadOnlyApiToken::class]);
    Route::patch('/applications/{uuid}/envs', [ApplicationsController::class, 'update_env_by_uuid']);
    Route::delete('/applications/{uuid}/envs/{env_uuid}', [ApplicationsController::class, 'delete_env_by_uuid'])->middleware([ReadOnlyApiToken::class]);

    Route::match(['get', 'post'], '/applications/{uuid}/action/deploy', [ApplicationsController::class, 'action_deploy'])->middleware([ReadOnlyApiToken::class]);
    Route::match(['get', 'post'], '/applications/{uuid}/action/restart', [ApplicationsController::class, 'action_restart'])->middleware([ReadOnlyApiToken::class]);
    Route::match(['get', 'post'], '/applications/{uuid}/action/stop', [ApplicationsController::class, 'action_stop'])->middleware([ReadOnlyApiToken::class]);

    Route::get('/databases', [DatabasesController::class, 'databases']);
    Route::post('/databases', [DatabasesController::class, 'create_database'])->middleware([ReadOnlyApiToken::class]);
    Route::get('/databases/{uuid}', [DatabasesController::class, 'database_by_uuid']);
    // Route::patch('/databases/{uuid}', [DatabasesController::class, 'update_by_uuid']);
    Route::delete('/databases/{uuid}', [DatabasesController::class, 'delete_by_uuid'])->middleware([ReadOnlyApiToken::class]);

    Route::delete('/envs/{env_uuid}', [EnvironmentVariablesController::class, 'delete_env_by_uuid'])->middleware([ReadOnlyApiToken::class]);

});

Route::any('/{any}', function () {
    return response()->json(['success' => false, 'message' => 'Not found.', 'docs' => 'https://coolify.io/docs'], 404);
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
