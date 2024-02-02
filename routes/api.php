<?php

use App\Actions\Database\StartMariadb;
use App\Actions\Database\StartMongodb;
use App\Actions\Database\StartMysql;
use App\Actions\Database\StartPostgresql;
use App\Actions\Database\StartRedis;
use App\Actions\Service\StartService;
use App\Http\Controllers\Api\Deploy;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Tag;
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
// Route::group([
//     'middleware' => $middlewares,
//     'prefix' => 'v1'
// ], function () {
//     Route::get('/deployments', function () {
//         return ApplicationDeploymentQueue::whereIn("status", ["in_progress", "queued"])->get([
//             "id",
//             "server_id",
//             "status"
//         ])->groupBy("server_id")->map(function ($item) {
//             return $item;
//         })->toArray();
//     });
// });
Route::group([
    'middleware' => ['auth:sanctum'],
    'prefix' => 'v1'
], function () {
    Route::get('/deploy', [Deploy::class, 'deploy']);
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
