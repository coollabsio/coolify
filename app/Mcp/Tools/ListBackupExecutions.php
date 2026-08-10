<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\ScheduledDatabaseBackup;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListBackupExecutions extends Tool
{
    protected string $name = 'list_backup_executions';

    protected string $description = 'List backup executions for a scheduled database backup owned by the authenticated team. Execution messages require read:sensitive and are best-effort redacted.';

    use BuildsResponse;
    use ResolvesTeam;

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'read', $this->name)) {
            return $error;
        }

        $teamId = $this->resolveTeamId($request);
        if (is_null($teamId)) {
            return $this->mcpError($request, 'Invalid token.');
        }

        $databaseUuid = $request->get('database_uuid');
        $backupUuid = $request->get('scheduled_backup_uuid');

        if (! is_string($databaseUuid) || $databaseUuid === '') {
            return $this->mcpError($request, 'database_uuid argument is required.');
        }
        if (! is_string($backupUuid) || $backupUuid === '') {
            return $this->mcpError($request, 'scheduled_backup_uuid argument is required.');
        }

        $database = queryDatabaseByUuidWithinTeam($databaseUuid, (string) $teamId);
        if (! $database) {
            return $this->mcpError($request, "Database [{$databaseUuid}] not found.", ['resource_uuid' => $databaseUuid]);
        }

        $backup = ScheduledDatabaseBackup::ownedByCurrentTeamAPI($teamId)
            ->where('uuid', $backupUuid)
            ->where('database_id', $database->id)
            ->where('database_type', $database->getMorphClass())
            ->first();

        if (! $backup) {
            return $this->mcpError($request, "Backup schedule [{$backupUuid}] not found.", ['resource_uuid' => $backupUuid]);
        }

        // Free-form execution output can embed secrets; gate like get_logs / task executions.
        $token = $request->user()?->currentAccessToken();
        $includeMessage = $token !== null && ($token->can('root') || $token->can('read:sensitive'));

        $args = $this->paginationArgs($request);
        $query = $backup->executions()->orderByDesc('created_at')->orderByDesc('id');
        $total = (clone $query)->count();

        $executions = $query
            ->skip($args['offset'])
            ->take($args['per_page'])
            ->get()
            ->map(function ($ex) use ($includeMessage) {
                $row = [
                    'uuid' => $ex->uuid ?? null,
                    'status' => $ex->status ?? null,
                    'message_included' => $includeMessage,
                    'size' => $ex->size ?? null,
                    'filename' => $ex->filename ?? null,
                    'created_at' => $ex->created_at,
                    'updated_at' => $ex->updated_at,
                ];
                if ($includeMessage) {
                    $message = $ex->message;
                    $row['message'] = is_string($message) ? $this->redactLogText($message) : $message;
                }

                return $this->scrubSensitive($row);
            })
            ->values()
            ->all();

        return $this->mcpSuccess($request, $this->respond(
            [
                'database_uuid' => $databaseUuid,
                'scheduled_backup_uuid' => $backupUuid,
                'message_included' => $includeMessage,
                'executions' => $executions,
            ],
            [],
            $this->paginationMeta('list_backup_executions', $args, $total, [
                'database_uuid' => $databaseUuid,
                'scheduled_backup_uuid' => $backupUuid,
            ]),
        ), ['resource_uuid' => $backupUuid]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'database_uuid' => $schema->string()->description('Database UUID.')->required(),
            'scheduled_backup_uuid' => $schema->string()->description('Scheduled backup UUID.')->required(),
            'page' => $schema->integer()->description('Page number (default 1).'),
            'per_page' => $schema->integer()->description('Items per page (default 50, max 100).'),
        ];
    }
}
