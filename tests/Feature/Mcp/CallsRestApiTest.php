<?php

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\CallsRestApi;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;

test('REST bridge removes nested database credentials even with sensitive read access', function (string $key) {
    Route::setRoutes(new RouteCollection);
    Route::post('/api/v1/mcp-credential-test', function (Request $request) use ($key) {
        $request->attributes->set('can_read_sensitive', true);

        return response()->json(['data' => [
            ['uuid' => 'database-uuid', $key => 'credential'],
            ['uuid' => 'another-uuid', $key => ['credential']],
        ]]);
    });

    $bridge = new class
    {
        use BuildsResponse;
        use CallsRestApi {
            callRestApi as public call;
        }
    };

    expect($bridge->call('POST', 'mcp-credential-test'))->toBe([
        'status' => 200,
        'body' => ['data' => [
            ['uuid' => 'database-uuid'],
            ['uuid' => 'another-uuid'],
        ]],
    ]);
})->with([
    'internal_db_url', 'external_db_url', 'password',
    'postgres_password', 'dragonfly_password', 'keydb_password',
    'redis_password', 'mongo_initdb_root_password',
    'mariadb_password', 'mariadb_root_password',
    'mysql_password', 'mysql_root_password', 'clickhouse_admin_password',
]);
