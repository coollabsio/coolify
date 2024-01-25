<?php

use App\Actions\Database\StartMariadb;
use App\Actions\Database\StartMongodb;
use App\Actions\Database\StartMysql;
use App\Actions\Database\StartPostgresql;
use App\Actions\Database\StartRedis;
use App\Actions\Service\StartService;
use App\Models\ApplicationDeploymentQueue;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Visus\Cuid2\Cuid2;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

$middlewares = ['auth:sanctum'];
if (isDev()) {
    $middlewares = [];
}

Route::get('/health', function () {
    return 'OK';
});
Route::group([
    'middleware' => $middlewares,
    'prefix' => 'v1'
], function () {
    Route::get('/deployments', function() {
        return ApplicationDeploymentQueue::whereIn("status", ["in_progress", "queued"])->get([
            "id",
            "server_id",
            "status"
          ])->groupBy("server_id")->map(function($item) {
            return $item;
          })->toArray();
    });
});
Route::group([
    'middleware' => ['auth:sanctum'],
    'prefix' => 'v1'
], function () {
    Route::get('/deploy', function (Request $request) {
        $token = auth()->user()->currentAccessToken();
        $teamId = data_get($token, 'team_id');
        $uuid = $request->query->get('uuid');
        $uuids = explode(',', $uuid);
        $uuids = collect(array_filter($uuids));
        $force = $request->query->get('force') ?? false;
        if (is_null($teamId)) {
            return response()->json(['error' => 'Invalid token.', 'docs' => 'https://coolify.io/docs/api/authentication'], 400);
        }
        if (count($uuids) === 0) {
            return response()->json(['error' => 'No UUIDs provided.', 'docs' => 'https://coolify.io/docs/api/deploy-webhook'], 400);
        }
        $message = collect([]);
        foreach ($uuids as $uuid) {
            $resource = getResourceByUuid($uuid, $teamId);
            if ($resource) {
                $type = $resource->getMorphClass();
                if ($type === 'App\Models\Application') {
                    queue_application_deployment(
                        server_id: $resource->destination->server->id,
                        application_id: $resource->id,
                        deployment_uuid: new Cuid2(7),
                        force_rebuild: $force,
                    );
                    $message->push("Application {$resource->name} deployment queued.");
                } else if ($type === 'App\Models\StandalonePostgresql') {
                    if (str($resource->status)->startsWith('running')) {
                        $message->push("Database {$resource->name} already running.");
                    }
                    StartPostgresql::run($resource);
                    $resource->update([
                        'started_at' => now(),
                    ]);
                    $message->push("Database {$resource->name} started.");
                } else if ($type === 'App\Models\StandaloneRedis') {
                    if (str($resource->status)->startsWith('running')) {
                        $message->push("Database {$resource->name} already running.");
                    }
                    StartRedis::run($resource);
                    $resource->update([
                        'started_at' => now(),
                    ]);
                    $message->push("Database {$resource->name} started.");
                } else if ($type === 'App\Models\StandaloneMongodb') {
                    if (str($resource->status)->startsWith('running')) {
                        $message->push("Database {$resource->name} already running.");
                    }
                    StartMongodb::run($resource);
                    $resource->update([
                        'started_at' => now(),
                    ]);
                    $message->push("Database {$resource->name} started.");
                } else if ($type === 'App\Models\StandaloneMysql') {
                    if (str($resource->status)->startsWith('running')) {
                        $message->push("Database {$resource->name} already running.");
                    }
                    StartMysql::run($resource);
                    $resource->update([
                        'started_at' => now(),
                    ]);
                    $message->push("Database {$resource->name} started.");
                } else if ($type === 'App\Models\StandaloneMariadb') {
                    if (str($resource->status)->startsWith('running')) {
                        $message->push("Database {$resource->name} already running.");
                    }
                    StartMariadb::run($resource);
                    $resource->update([
                        'started_at' => now(),
                    ]);
                    $message->push("Database {$resource->name} started.");
                } else if ($type === 'App\Models\Service') {
                    StartService::run($resource);
                    $message->push("Service {$resource->name} started. It could take a while, be patient.");
                }
            }
        }
        if ($message->count() > 0) {
            return response()->json(['message' => $message->toArray()], 200);
        }
        return response()->json(['error' => "No resources found.", 'docs' => 'https://coolify.io/docs/api/deploy-webhook'], 404);
    });
});

Route::middleware(['throttle:5'])->group(function () {
    Route::get('/unsubscribe/{token}', function () {
        try {
            $token = request()->token;
            $email = decrypt($token);
            if (!User::whereEmail($email)->exists()) {
                return redirect(RouteServiceProvider::HOME);
            }
            if (User::whereEmail($email)->first()->marketing_emails === false) {
                return 'You have already unsubscribed from marketing emails.';
            }
            User::whereEmail($email)->update(['marketing_emails' => false]);
            return 'You have been unsubscribed from marketing emails.';
        } catch (\Throwable $e) {
            return 'Something went wrong. Please try again or contact support.';
        }
    })->name('unsubscribe.marketing.emails');
});
