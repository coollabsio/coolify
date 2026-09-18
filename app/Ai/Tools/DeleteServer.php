<?php

namespace App\Ai\Tools;

use App\Actions\Server\DeleteServer as DeleteServerAction;
use App\Ai\Concerns\AuthorizesToolAction;
use App\Ai\Contracts\HasApprovalForm;
use App\Ai\Ui\ApprovalForm;
use App\Ai\Ui\Field;
use App\Ai\Ui\FieldType;
use App\Models\Server;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class DeleteServer implements Approvable, HasApprovalForm, Tool
{
    use AuthorizesToolAction;
    use InteractsWithApprovals;

    public function description(): string
    {
        return 'Permanently delete a server from the current team. Destructive and irreversible; '
            .'requires admin or owner role and an explicit human approval. The server must have no resources and must not be the Coolify host.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'server_uuid' => $schema->string()->description('Server UUID.')->required(),
        ];
    }

    public function approvalForm(array $arguments): ApprovalForm
    {
        return new ApprovalForm('Delete server', true, [
            new Field(FieldType::Locked, 'server_uuid', 'Server', $arguments['server_uuid'] ?? ''),
            new Field(FieldType::Note, 'warning', '', 'This permanently deletes the server and cannot be undone.'),
        ]);
    }

    protected function needsApproval(Request $request): Approval|bool
    {
        $server = $this->resolve($request);
        if (! $server) {
            return false; // not resolvable => handle() reports not-found, nothing destructive to gate.
        }

        $this->authorizeToolAction('delete', $server, 'delete_server');

        return Approval::required("Delete server \"{$server->name}\" ({$server->uuid}). This is permanent and cannot be undone.");
    }

    public function handle(Request $request): string
    {
        $uuid = (string) $request->validate(['server_uuid' => 'required|string'])['server_uuid'];

        $server = $this->resolve($request);
        if (! $server) {
            return "Server [{$uuid}] was not found in this team.";
        }

        $this->authorizeToolAction('delete', $server, 'delete_server');

        if ($server->is_coolify_host) {
            return 'The Coolify host server cannot be deleted.';
        }

        if ($server->hasDefinedResources()) {
            return "Server \"{$server->name}\" still has resources. Delete them first.";
        }

        $server->delete();
        DeleteServerAction::dispatch($server->id, false, $server->hetzner_server_id, $server->cloud_provider_token_id, $server->team_id);

        $this->auditToolCall('delete_server', 'success', ['server_uuid' => $server->uuid]);

        return "Server \"{$server->name}\" ({$server->uuid}) deleted.";
    }

    private function resolve(Request $request): ?Server
    {
        $uuid = $request->all()['server_uuid'] ?? null;
        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        return Server::where('team_id', $this->actingTeamId())->where('uuid', $uuid)->first();
    }
}
