<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

class TroubleshootApplication extends Prompt
{
    protected string $name = 'troubleshoot_application';

    protected string $description = 'DB-first guided workflow to diagnose an application. Live logs are optional and only when the container is running.';

    public function handle(Request $request): Response
    {
        $uuid = $request->get('uuid');
        $uuid = is_string($uuid) && $uuid !== '' ? $uuid : '{application_uuid}';

        $text = <<<MD
You are troubleshooting a Coolify application (UUID: `{$uuid}`) for the authenticated team.

Use Coolify MCP tools over HTTP only (no shell/DB). Do not invent UUIDs. Team scope is enforced by the API token.

## Phase A — always available (Coolify DB / API metadata)
1. `get_application` uuid=`{$uuid}` — name, status, fqdn, git, build pack.
2. If status is not running (or unknown): `list_unhealthy_resources` with sample_only=true, then confirm this app is listed.
3. `list_deployments` application_uuid=`{$uuid}` — recent deploy statuses.
4. For any failed/cancelled/in_progress deploy: `get_deployment` with that deployment_uuid and **include_log_summary=true** (build failure context without full logs).
5. `list_env_keys` resource=application uuid=`{$uuid}` — key **names only** (never values).
6. `list_application_previews` uuid=`{$uuid}` if the issue may be PR-related.
7. `list_storages` / `list_scheduled_tasks` if storage or cron is relevant.

## Phase B — optional live host tools (only if status starts with running)
8. `get_logs` resource=application uuid=`{$uuid}` lines=100–200.
   - If response has `ok: false`, use `reason` and `next_tools` — do **not** retry logs blindly.
   - Common reasons: not_running, server_unreachable, no_server.

## Phase C — lifecycle (only if user asked to fix and token has deploy ability)
9. `control` or `deploy` as appropriate; then poll `get_deployment`.

## Summary format
- Current status and whether server is reachable
- Last successful vs last failed deploy (or none)
- Evidence from deploy log_summary if any
- Missing env key names if any
- Recommended next operator actions (or control/deploy if already authorized)

Never request secrets, private keys, or unbounded build logs.
MD;

        return Response::text($text);
    }

    public function arguments(): array
    {
        return [
            new Argument(
                name: 'uuid',
                description: 'Application UUID to troubleshoot.',
                required: true,
            ),
        ];
    }
}
