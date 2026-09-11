<?php

use App\Enums\AiProvider;
use App\Jobs\Ai\RunAssistantTurn;
use App\Livewire\Ai\Assistant;
use App\Models\AiConversation;
use App\Models\AiProviderCredential;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'admin']);
    $this->actingAs($this->user);
    session(['currentTeam' => ['id' => $this->team->id]]);
});

test('the assistant is enabled only when both flags are on', function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_ai_assistant_enabled' => true]);
    Once::flush();

    expect(Livewire::test(Assistant::class)->instance()->enabled())->toBeTrue();
});

test('the launcher morphs the icon and the header shows an online status', function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_ai_assistant_enabled' => true]);
    Once::flush();

    $html = Livewire::test(Assistant::class)->html();

    expect($html)
        ->toContain(':aria-expanded="open"')                 // launcher toggles state
        ->toContain('m6 9 6 6 6-6')                           // minimize chevron path
        ->toContain('rotate-90 scale-75')                     // morph transform
        ->toContain('bg-success')                             // online status dot
        ->toContain('>Online<');                             // status label
});

test('the floating widget hides itself on the full-page assistant', function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_ai_assistant_enabled' => true]);
    Once::flush();

    $html = Livewire::test(Assistant::class)->html();

    expect($html)
        ->toContain('x-show="!onAssistantPage"')
        ->toContain("window.location.pathname.startsWith('/assistant')");
});

test('the assistant is disabled when the instance flag is off', function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_ai_assistant_enabled' => false]);
    Once::flush();

    expect(Livewire::test(Assistant::class)->instance()->enabled())->toBeFalse();
});

test('opening with no recent session shows the empty composer without creating a conversation', function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_ai_assistant_enabled' => true]);
    Once::flush();

    Livewire::test(Assistant::class)
        ->call('openThread')
        ->assertSet('activeConversationId', null);

    expect(AiConversation::where('team_id', $this->team->id)->count())->toBe(0);
});

test('opening resumes a recent conversation instead of creating a new one', function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_ai_assistant_enabled' => true]);
    Once::flush();

    $recent = AiConversation::factory()->for($this->team)->create([
        'created_by_user_id' => $this->user->id,
        'updated_at' => now()->subMinutes(30),
    ]);

    Livewire::test(Assistant::class)
        ->call('openThread')
        ->assertSet('activeConversationId', $recent->id);

    expect(AiConversation::where('team_id', $this->team->id)->count())->toBe(1);
});

test('opening does not resume or create when the last session is over an hour idle', function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_ai_assistant_enabled' => true]);
    Once::flush();

    AiConversation::factory()->for($this->team)->create([
        'created_by_user_id' => $this->user->id,
        'updated_at' => now()->subHours(2),
    ]);

    Livewire::test(Assistant::class)
        ->call('openThread')
        ->assertSet('activeConversationId', null);

    expect(AiConversation::where('team_id', $this->team->id)->count())->toBe(1); // unchanged, none created
});

test('new chat resets to the empty composer without creating a conversation', function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_ai_assistant_enabled' => true]);
    Once::flush();

    $recent = AiConversation::factory()->for($this->team)->create([
        'created_by_user_id' => $this->user->id,
        'updated_at' => now()->subMinutes(5),
    ]);

    Livewire::test(Assistant::class)
        ->set('activeConversationId', $recent->id)
        ->call('newThread')
        ->assertSet('activeConversationId', null);

    expect(AiConversation::where('team_id', $this->team->id)->count())->toBe(1); // unchanged
});

test('the widget starts a conversation only when a message is sent', function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_ai_assistant_enabled' => true]);
    $this->team->update(['is_ai_assistant_enabled' => true]);
    Once::flush();
    Bus::fake();
    AiProviderCredential::factory()->for($this->team)->create([
        'provider' => AiProvider::OPENAI, 'model' => 'gpt-5', 'is_default' => true, 'enabled' => true,
    ]);

    Livewire::test(Assistant::class)
        ->call('startConversation', 'How many servers do I have?')
        ->assertSet('activeConversationId', fn ($id) => $id !== null);

    expect(AiConversation::where('team_id', $this->team->id)->count())->toBe(1);
    Bus::assertDispatched(RunAssistantTurn::class);
});

test('the widget header mirrors the page: shows the conversation title and links to the full page', function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_ai_assistant_enabled' => true]);
    $this->team->update(['is_ai_assistant_enabled' => true]);
    Once::flush();

    $conversation = AiConversation::factory()->for($this->team)->create([
        'created_by_user_id' => $this->user->id, 'title' => 'Prod disk cleanup',
    ]);

    $html = Livewire::test(Assistant::class)
        ->set('activeConversationId', $conversation->id)
        ->html();

    expect($html)
        ->toContain('Prod disk cleanup')                                          // title, like the page
        ->and($html)->toContain('title="Open full page"')                         // deep-link to full page
        ->and($html)->toContain(route('ai.assistant.show', ['uuid' => $conversation->uuid]));
});
