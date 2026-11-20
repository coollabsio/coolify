<?php

use App\Events\Ai\AssistantStreamDelta;
use App\Models\AiConversation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->creator = User::factory()->create();
    $this->creator->teams()->attach($this->team, ['role' => 'member']);
    $this->teammate = User::factory()->create();
    $this->teammate->teams()->attach($this->team, ['role' => 'member']);
    $this->outsider = User::factory()->create();
    $this->outsider->teams()->attach(Team::factory()->create(), ['role' => 'admin']);
});

test('a delta broadcasts on the private conversation channel', function () {
    $event = new AssistantStreamDelta('conv-uuid-1', 3, 'hello');
    $channels = $event->broadcastOn();

    expect($channels[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($channels[0]->name)->toBe('private-ai-conversation.conv-uuid-1')
        ->and($event->sequence)->toBe(3);
});

test('the channel callback enforces AiConversationPolicy', function () {
    require base_path('routes/channels.php');

    $private = AiConversation::factory()->for($this->team)->create([
        'created_by_user_id' => $this->creator->id,
        'visibility' => AiConversation::VISIBILITY_PRIVATE,
    ]);
    $shared = AiConversation::factory()->for($this->team)->create([
        'created_by_user_id' => $this->creator->id,
        'visibility' => AiConversation::VISIBILITY_TEAM,
    ]);

    $callback = Broadcast::getChannels()['ai-conversation.{uuid}'] ?? null;
    expect($callback)->not->toBeNull();

    expect((bool) $callback($this->creator, $private->uuid))->toBeTrue()
        ->and((bool) $callback($this->teammate, $private->uuid))->toBeFalse()
        ->and((bool) $callback($this->teammate, $shared->uuid))->toBeTrue()
        ->and((bool) $callback($this->outsider, $shared->uuid))->toBeFalse();
});
