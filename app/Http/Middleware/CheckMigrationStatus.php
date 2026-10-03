<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;

class CheckMigrationStatus
{
    public function handle(Request $request, Closure $next): Response
    {
        $migrationFailedFile = storage_path('app/migration-failed.json');

        // The upgrade dialog waits for the health endpoint before it reloads.
        if ($request->is('api/health', 'api/v1/health') || ! File::exists($migrationFailedFile)) {
            return $next($request);
        }

        $migrationFailure = File::json($migrationFailedFile);

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['message' => "Database migration failed: {$migrationFailure['error']}"], 503);
        }

        return response()->view('errors.migration-failed', $migrationFailure, 503);
    }
}
