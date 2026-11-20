<?php

use App\Ai\Agents\CoolifyAssistant;
use App\Ai\Exceptions\NoAiCredentialException;
use App\Ai\RunAssistant;
use App\Enums\AiProvider;
use App\Models\AiProviderCredential;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Ai;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::updateOrCreate(['id' => 0]);
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'admin']);
    $this->actingAs($this->user);
    session(['currentTeam' => ['id' => $this->team->id]]);
});

test('it prompts the assistant with the team default credential and returns text', function () {
    AiProviderCredential::factory()->for($this->team)->create([
        'provider' => AiProvider::OPENAI,
        'model' => 'gpt-5',
        'is_default' => true,
        'enabled' => true,
    ]);

    Ai::fakeAgent(CoolifyAssistant::class, ['You have 2 servers, both healthy.']);

    $answer = app(RunAssistant::class)->handle($this->team, 'How many servers do I have?');

    expect($answer)->toBe('You have 2 servers, both healthy.');
    Ai::assertAgentWasPrompted(CoolifyAssistant::class, fn ($prompt) => str_contains($prompt->prompt, 'How many servers'));
});

test('it throws when the team has no enabled credential', function () {
    expect(fn () => app(RunAssistant::class)->handle($this->team, 'hi'))
        ->toThrow(NoAiCredentialException::class);
});
