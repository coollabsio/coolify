<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Index definitions: [table, columns, index_name]
     */
    private array $indexes = [
        ['servers', ['team_id'], 'idx_servers_team_id'],
        ['private_keys', ['team_id'], 'idx_private_keys_team_id'],
        ['projects', ['team_id'], 'idx_projects_team_id'],
        ['subscriptions', ['team_id'], 'idx_subscriptions_team_id'],
        ['cloud_init_scripts', ['team_id'], 'idx_cloud_init_scripts_team_id'],
        ['cloud_provider_tokens', ['team_id'], 'idx_cloud_provider_tokens_team_id'],
        ['application_deployment_queues', ['status', 'server_id'], 'idx_deployment_queues_status_server'],
        ['application_deployment_queues', ['application_id', 'status', 'pull_request_id', 'created_at'], 'idx_deployment_queues_app_status_pr_created'],
        ['environments', ['project_id'], 'idx_environments_project_id'],
    ];

    public function up(): void
    {
        foreach ($this->indexes as [$table, $columns, $indexName]) {
            if (! $this->indexExists($indexName)) {
                $columnList = implode(', ', array_map(fn ($col) => "\"$col\"", $columns));
                DB::statement("CREATE INDEX \"{$indexName}\" ON \"{$table}\" ({$columnList})");
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as [, , $indexName]) {
            DB::statement("DROP INDEX IF EXISTS \"{$indexName}\"");
        }
    }

    private function indexExists(string $indexName): bool
    {
        $result = DB::selectOne(
            'SELECT 1 FROM pg_indexes WHERE indexname = ?',
            [$indexName]
        );

        return $result !== null;
    }
};
