<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('the conversation tables exist with the authorship column', function () {
    expect(Schema::hasTable('agent_conversations'))->toBeTrue()
        ->and(Schema::hasTable('agent_conversation_messages'))->toBeTrue()
        ->and(Schema::hasColumn('agent_conversation_messages', 'author_user_id'))->toBeTrue()
        ->and(Schema::hasTable('ai_conversations'))->toBeTrue()
        ->and(Schema::hasColumn('ai_conversations', 'visibility'))->toBeTrue()
        ->and(Schema::hasColumn('ai_conversations', 'sdk_conversation_id'))->toBeTrue()
        ->and(Schema::hasColumn('ai_conversations', 'status'))->toBeTrue();
});
