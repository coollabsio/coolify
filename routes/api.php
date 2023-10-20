<?php

use App\Actions\Database\StartMongodb;
use App\Actions\Database\StartPostgresql;
use App\Actions\Database\StartRedis;
use App\Actions\Service\StartService;
use App\Models\User;
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

Route::get('/health', function () {
    return 'OK';
});
Route::group([
    'middleware' => ['auth:sanctum'],
    'prefix' => 'v1'
], function () {
    Route::get('/deploy', function (Request $request) {
        $token = auth()->user()->currentAccessToken();
        $teamId = data_get($token, 'team_id');
        $uuid = $request->query->get('uuid');
        $force = $request->query->get('force') ?? false;

        if (is_null($teamId)) {
            return response()->json(['error' => 'Invalid token.'], 400);
        }
        if (!$uuid) {
            return response()->json(['error' => 'No UUID provided.'], 400);
        }
        $resource = getResourceByUuid($uuid, $teamId);
        if ($resource) {
            $type = $resource->getMorphClass();
            if ($type === 'App\Models\Application') {
                queue_application_deployment(
                    application_id: $resource->id,
                    deployment_uuid: new Cuid2(7),
                    force_rebuild: $force,
                );
                return response()->json(['message' => 'Deployment queued.'], 200);
            } else if ($type === 'App\Models\StandalonePostgresql') {
                StartPostgresql::run($resource);
                $resource->update([
                    'started_at' => now(),
                ]);
                return response()->json(['message' => 'Database started.'], 200);
            } else if ($type === 'App\Models\StandaloneRedis') {
                StartRedis::run($resource);
                $resource->update([
                    'started_at' => now(),
                ]);
                return response()->json(['message' => 'Database started.'], 200);
            } else if ($type === 'App\Models\StandaloneMongodb') {
                StartMongodb::run($resource);
                $resource->update([
                    'started_at' => now(),
                ]);
                return response()->json(['message' => 'Database started.'], 200);
            }else if ($type === 'App\Models\Service') {
                StartService::run($resource);
                return response()->json(['message' => 'Service started.'], 200);
            }
        }
        return response()->json(['error' => 'No resource found.'], 404);
    });
});

Route::middleware(['throttle:5'])->group(function () {
    Route::get('/unsubscribe/{token}', function () {
        try {
            $token = request()->token;
            $email = decrypt($token);
            if (!User::whereEmail($email)->exists()) {
                return redirect('/');
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
