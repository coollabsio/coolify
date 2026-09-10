<?php

use App\Enums\AiProvider;
use App\Livewire\Settings\Ai;
use App\Models\AiProviderCredential;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_ai_assistant_enabled' => true]);
    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'admin']);
    $this->member = User::factory()->create();
    $this->member->teams()->attach($this->team, ['role' => 'member']);
});

test('admin can add a credential and it becomes default when first', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => ['id' => $this->team->id]]);

    Livewire::test(Ai::class)
        ->set('newProvider', AiProvider::OPENAI->value)
        ->set('newModel', 'gpt-5')
        ->set('newApiKey', 'sk-abc')
        ->call('addCredential')
        ->assertHasNoErrors();

    $cred = AiProviderCredential::where('team_id', $this->team->id)->first();
    expect($cred)->not->toBeNull()
        ->and($cred->is_default)->toBeTrue()
        ->and($cred->api_key)->toBe('sk-abc');
});

test('member cannot open the ai settings page', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => ['id' => $this->team->id]]);

    Livewire::test(Ai::class)->assertRedirect(route('dashboard'));
});

test('testCredential surfaces a success message', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => ['id' => $this->team->id]]);
    $cred = AiProviderCredential::factory()->for($this->team)->create([
        'provider' => AiProvider::OPENAI_COMPATIBLE,
        'model' => 'local-model',
        'base_url' => 'http://prov.test/v1',
    ]);
    Http::fake(['prov.test/*' => Http::response([
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'OK'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
    ])]);

    Livewire::test(Ai::class)
        ->call('testCredential', $cred->id)
        ->assertDispatched('success');
});

test('member of another team cannot delete this team credential', function () {
    $cred = AiProviderCredential::factory()->for($this->team)->create();
    $otherTeam = Team::factory()->create();
    $otherAdmin = User::factory()->create();
    $otherAdmin->teams()->attach($otherTeam, ['role' => 'admin']);
    $this->actingAs($otherAdmin);
    session(['currentTeam' => ['id' => $otherTeam->id]]);

    Livewire::test(Ai::class)
        ->call('deleteCredential', $cred->id)
        ->assertDispatched('error');

    expect(AiProviderCredential::find($cred->id))->not->toBeNull();
});
