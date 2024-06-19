<?php

use App\Http\Controllers\Api\Deploy;
use App\Http\Controllers\Api\Domains;
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
    Route::get('/deploy', [Deploy::class, 'deploy']);
    Route::get('/deployments', [Deploy::class, 'deployments']);

    Route::get('/servers', [Server::class, 'servers']);
    Route::get('/server/{uuid}', [Server::class, 'server_by_uuid']);

    Route::get('/resources', [Resources::class, 'resources']);
    Route::get('/domains', [Domains::class, 'domains']);
    Route::put('/domains', [Domains::class, 'updateDomains']);
    Route::delete('/domains', [Domains::class, 'deleteDomains']);
    Route::get('/teams', [Team::class, 'teams']);
    Route::get('/team/current', [Team::class, 'current_team']);
    Route::get('/team/current/members', [Team::class, 'current_team_members']);
    Route::get('/team/{id}', [Team::class, 'team_by_id']);
    Route::get('/team/{id}/members', [Team::class, 'members_by_id']);

    //Route::get('/projects', [Project::class, 'projects']);
    //Route::get('/project/{uuid}', [Project::class, 'project_by_uuid']);
    //Route::get('/project/{uuid}/{environment_name}', [Project::class, 'environment_details']);
});

Route::get('/{any}', function () {
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
