<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\ScheduledDatabaseBackup;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListDatabaseBackups extends Tool
{
    protected string $name = 'list_database_backups';

    protected string $description = 'List backup schedules for a standalone database owned by the authenticated team.';

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

        $uuid = $request->get('uuid');
        if (! is_string($uuid) || $uuid === '') {
            return $this->mcpError($request, 'uuid argument is required.');
        }

        $database = queryDatabaseByUuidWithinTeam($uuid, (string) $teamId);
        if (! $database) {
            return $this->mcpError($request, "Database [{$uuid}] not found.", ['resource_uuid' => $uuid]);
        }

        $backups = ScheduledDatabaseBackup::ownedByCurrentTeamAPI($teamId)
            ->where('database_id', $database->id)
            ->where('database_type', $database->getMorphClass())
            ->get()
            ->map(fn ($backup) => $this->scrubSensitive([
                'uuid' => $backup->uuid,
                'enabled' => $backup->enabled ?? null,
                'frequency' => $backup->frequency ?? null,
                'database_backup_retention_amount_locally' => $backup->database_backup_retention_amount_locally ?? null,
                'save_s3' => $backup->save_s3 ?? null,
                's3_storage_uuid' => $backup->s3?->uuid ?? null,
                'created_at' => $backup->created_at,
                'updated_at' => $backup->updated_at,
            ]))
            ->values()
            ->all();

        return $this->mcpSuccess($request, $this->respond([
            'database_uuid' => $uuid,
            'backups' => $backups,
        ]), ['resource_uuid' => $uuid]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->description('Database UUID.')->required(),
        ];
    }
}
