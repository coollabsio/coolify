<?php

namespace App\Ai\Tools;

use App\Actions\Server\RunCommand;
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

class RunServerCommand implements Approvable, HasApprovalForm, Tool
{
    use AuthorizesToolAction;
    use InteractsWithApprovals;

    public function description(): string
    {
        return 'Run an arbitrary shell command on a team server over SSH. Dangerous; requires admin or owner role '
            .'and an explicit human approval. Provide server_uuid and command.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'server_uuid' => $schema->string()->description('Server UUID.')->required(),
            'command' => $schema->string()->description('The shell command to run.')->required(),
        ];
    }

    public function approvalForm(array $arguments): ApprovalForm
    {
        return new ApprovalForm('Run server command', true, [
            new Field(FieldType::Textarea, 'command', 'Command', $arguments['command'] ?? '', required: true),
            new Field(FieldType::Locked, 'server_uuid', 'Server', $arguments['server_uuid'] ?? ''),
            new Field(FieldType::Note, 'warning', '', 'This runs a shell command on the server over SSH.'),
        ]);
    }

    protected function needsApproval(Request $request): Approval|bool
    {
        $server = $this->resolve($request);
        if (! $server) {
            return false;
        }

        $this->authorizeToolAction('update', $server, 'run_server_command');

        $command = (string) ($request->all()['command'] ?? '');

        return Approval::required("Run command on server \"{$server->name}\" ({$server->uuid}): {$command}");
    }

    public function handle(Request $request): string
    {
        $args = $request->validate([
            'server_uuid' => 'required|string',
            'command' => 'required|string',
        ]);

        $server = $this->resolve($request);
        if (! $server) {
            return "Server [{$args['server_uuid']}] was not found in this team.";
        }

        $this->authorizeToolAction('update', $server, 'run_server_command');

        RunCommand::run($server, $args['command']);

        $this->auditToolCall('run_server_command', 'success', [
            'server_uuid' => $server->uuid,
            'command' => $args['command'],
        ]);

        return "Command queued on \"{$server->name}\": {$args['command']}";
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
