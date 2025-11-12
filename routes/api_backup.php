<?php

use App\Http\Controllers\Api\BackupController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Backup API Routes
|--------------------------------------------------------------------------
|
| These routes provide API access to the backup functionality including
| pgBackRest integration for PostgreSQL databases.
|
*/

Route::middleware(['auth:sanctum'])->group(function () {
    
    // Database backup configuration routes
    Route::prefix('databases/{database}')->group(function () {
        // List all backup configurations for a database
        Route::get('/backups', [BackupController::class, 'index']);
        
        // Create a new backup configuration
        Route::post('/backups', [BackupController::class, 'store']);
        
        // Get pgBackRest status (PostgreSQL only)
        Route::get('/pgbackrest/status', [BackupController::class, 'pgbackrestStatus']);
    });
    
    // Backup execution routes
    Route::prefix('backups')->group(function () {
        // Trigger a backup manually
        Route::post('/{backup}/execute', [BackupController::class, 'execute']);
        
        // Get backup execution details
        Route::get('/executions/{execution}', [BackupController::class, 'showExecution']);
        
        // Restore from a backup
        Route::post('/executions/{execution}/restore', [BackupController::class, 'restore']);
    });
});
