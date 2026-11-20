<?php

namespace App\Listeners\Ai;

use Illuminate\Support\Facades\Context;
use Laravel\Ai\Events\ToolApprovalResolved;
use Laravel\Ai\Responses\Data\ToolResult;

class RecordToolApproval
{
    public function handle(ToolApprovalResolved $event): void
    {
        $approverId = Context::get('ai.author_user_id');

        $event->toolResults->each(function (ToolResult $result) use ($approverId, $event) {
            auditLog($result->denied ? 'ai.tool.rejected' : 'ai.tool.approved', [
                'approver_id' => $approverId,
                'tool' => $result->name,
                'call_id' => $result->id,
                'conversation_id' => $event->conversationId,
            ]);
        });
    }
}
