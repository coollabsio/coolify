<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\DatabaseBackupJob;
use App\Jobs\PgBackRestBackupJob;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\StandalonePostgresql;
use App\Services\PgBackRestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BackupController extends Controller
{
    /**
     * List all backups for a database
     * GET /api/databases/{database}/backups
     */
    public function index(Request $request, string $databaseUuid)
    {
        $database = StandalonePostgresql::where('uuid', $databaseUuid)->firstOrFail();
        
        $this->authorize('view', $database);
        
        $backupConfigs = $database->scheduledBackups()->with('executions')->get();
        
        return response()->json([
            'database' => [
                'uuid' => $database->uuid,
                'name' => $database->name,
            ],
            'backups' => $backupConfigs->map(function ($backup) {
                return [
                    'id' => $backup->id,
                    'uuid' => $backup->uuid,
                    'enabled' => $backup->enabled,
                    'frequency' => $backup->frequency,
                    'backup_engine' => $backup->backup_engine ?? 'pg_dump',
                    'uses_pgbackrest' => $backup->backup_engine === 'pgbackrest',
                    's3_enabled' => $backup->save_s3,
                    'retention' => [
                        'count' => $backup->number_of_backups_locally,
                        'days' => $backup->backup_retention_days,
                    ],
                    'executions' => $backup->executions->map(function ($execution) {
                        return [
                            'id' => $execution->id,
                            'status' => $execution->status,
                            'backup_type' => $execution->backup_type,
                            'size' => $execution->size,
                            'database_size' => $execution->database_size,
                            'filename' => $execution->filename,
                            'location' => $execution->location,
                            'started_at' => $execution->started_at,
                            'finished_at' => $execution->finished_at,
                            'duration' => $execution->finished_at 
                                ? round($execution->started_at->diffInSeconds($execution->finished_at), 2) 
                                : null,
                        ];
                    }),
                ];
            }),
        ]);
    }

    /**
     * Create a new backup configuration
     * POST /api/databases/{database}/backups
     */
    public function store(Request $request, string $databaseUuid)
    {
        $database = StandalonePostgresql::where('uuid', $databaseUuid)->firstOrFail();
        
        $this->authorize('update', $database);
        
        $validated = $request->validate([
            'enabled' => 'required|boolean',
            'frequency' => 'required|string',
            'backup_engine' => 'sometimes|string|in:pg_dump,pgbackrest',
            'save_s3' => 'required|boolean',
            's3_storage_id' => 'nullable|exists:s3_storages,id',
            'number_of_backups_locally' => 'required|integer|min:0',
            'backup_retention_days' => 'required|integer|min:0',
        ]);
        
        // Default to pgBackRest for new backups on PostgreSQL
        $backupEngine = $validated['backup_engine'] ?? 'pgbackrest';
        
        $backup = ScheduledDatabaseBackup::create([
            'database_id' => $database->id,
            'database_type' => get_class($database),
            'enabled' => $validated['enabled'],
            'frequency' => $validated['frequency'],
            'backup_engine' => $backupEngine,
            'use_pgbackrest' => $backupEngine === 'pgbackrest',
            'save_s3' => $validated['save_s3'],
            's3_storage_id' => $validated['s3_storage_id'],
            'number_of_backups_locally' => $validated['number_of_backups_locally'],
            'backup_retention_days' => $validated['backup_retention_days'],
        ]);
        
        return response()->json([
            'message' => 'Backup configuration created',
            'backup' => [
                'id' => $backup->id,
                'uuid' => $backup->uuid,
                'backup_engine' => $backup->backup_engine,
            ],
        ], 201);
    }

    /**
     * Trigger a backup manually
     * POST /api/backups/{backup}/execute
     */
    public function execute(Request $request, int $backupId)
    {
        $backup = ScheduledDatabaseBackup::findOrFail($backupId);
        
        $this->authorize('update', $backup->database);
        
        $validated = $request->validate([
            'type' => 'sometimes|string|in:full,diff,incr',
        ]);
        
        // Dispatch appropriate job based on backup engine
        if ($backup->backup_engine === 'pgbackrest') {
            // For manual triggers, we can specify the backup type
            if (isset($validated['type'])) {
                $backup->pgbackrest_config = array_merge(
                    $backup->pgbackrest_config ?? [],
                    ['force_type' => $validated['type']]
                );
                $backup->save();
            }
            
            PgBackRestBackupJob::dispatch($backup);
            $message = 'pgBackRest backup queued';
        } else {
            DatabaseBackupJob::dispatch($backup);
            $message = 'Backup queued';
        }
        
        return response()->json([
            'message' => $message,
            'backup_engine' => $backup->backup_engine,
        ]);
    }

    /**
     * Get backup execution details
     * GET /api/backups/executions/{execution}
     */
    public function showExecution(int $executionId)
    {
        $execution = ScheduledDatabaseBackupExecution::with('scheduledDatabaseBackup.database')
            ->findOrFail($executionId);
        
        $this->authorize('view', $execution->scheduledDatabaseBackup->database);
        
        return response()->json([
            'execution' => [
                'id' => $execution->id,
                'status' => $execution->status,
                'backup_type' => $execution->backup_type,
                'size' => $execution->size,
                'database_size' => $execution->database_size,
                'compression_ratio' => $execution->database_size > 0 
                    ? round((1 - $execution->size / $execution->database_size) * 100, 2) 
                    : null,
                'filename' => $execution->filename,
                'location' => $execution->location,
                'message' => $execution->message,
                'started_at' => $execution->started_at,
                'finished_at' => $execution->finished_at,
                'duration' => $execution->finished_at 
                    ? $execution->started_at->diffInSeconds($execution->finished_at) 
                    : null,
            ],
            'backup' => [
                'id' => $execution->scheduledDatabaseBackup->id,
                'backup_engine' => $execution->scheduledDatabaseBackup->backup_engine,
            ],
        ]);
    }

    /**
     * Restore from a backup
     * POST /api/backups/executions/{execution}/restore
     */
    public function restore(Request $request, int $executionId)
    {
        $execution = ScheduledDatabaseBackupExecution::with('scheduledDatabaseBackup.database')
            ->findOrFail($executionId);
        
        $this->authorize('update', $execution->scheduledDatabaseBackup->database);
        
        $validated = $request->validate([
            'target_time' => 'sometimes|date',
        ]);
        
        $backup = $execution->scheduledDatabaseBackup;
        $database = $backup->database;
        
        if ($backup->backup_engine === 'pgbackrest') {
            if (!$database instanceof StandalonePostgresql) {
                return response()->json([
                    'error' => 'Database is not a PostgreSQL instance',
                ], 400);
            }
            
            try {
                $pgBackRest = new PgBackRestService($database);
                $result = $pgBackRest->restore(
                    $execution->filename,
                    $validated['target_time'] ?? null
                );
                
                if (!$result['success']) {
                    throw new \Exception($result['message']);
                }
                
                return response()->json([
                    'message' => 'Database restored successfully',
                    'backup_type' => $execution->backup_type,
                    'restored_from' => $execution->filename,
                    'target_time' => $validated['target_time'] ?? null,
                ]);
            } catch (\Exception $e) {
                Log::error('Restore failed', [
                    'execution_id' => $executionId,
                    'error' => $e->getMessage(),
                ]);
                
                return response()->json([
                    'error' => 'Restore failed: ' . $e->getMessage(),
                ], 500);
            }
        } else {
            // Legacy pg_dump restore
            return response()->json([
                'message' => 'Please use the UI to restore pg_dump backups',
            ], 400);
        }
    }

    /**
     * Get pgBackRest status for a database
     * GET /api/databases/{database}/pgbackrest/status
     */
    public function pgbackrestStatus(string $databaseUuid)
    {
        $database = StandalonePostgresql::where('uuid', $databaseUuid)->firstOrFail();
        
        $this->authorize('view', $database);
        
        try {
            $pgBackRest = new PgBackRestService($database);
            $info = $pgBackRest->getBackupInfo();
            
            return response()->json([
                'configured' => $pgBackRest->isConfigured(),
                'stanza_name' => $pgBackRest->getStanzaName(),
                'backups' => $info['backups'],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'configured' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }
}