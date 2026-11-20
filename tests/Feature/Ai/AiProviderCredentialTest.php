<?php

use App\Enums\AiProvider;
use App\Models\AiProviderCredential;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('api key is encrypted at rest and hidden from arrays', function () {
    $team = Team::factory()->create();
    $cred = AiProviderCredential::create([
        'team_id' => $team->id,
        'provider' => AiProvider::OPENAI,
        'model' => 'gpt-5',
        'api_key' => 'sk-secret-123',
        'is_default' => true,
    ]);

    $raw = DB::table('ai_provider_credentials')->where('id', $cred->id)->value('api_key');
    expect($raw)->not->toContain('sk-secret-123');
    expect($cred->fresh()->api_key)->toBe('sk-secret-123');
    expect($cred->toArray())->not->toHaveKey('api_key');
    expect($cred->provider)->toBe(AiProvider::OPENAI);
});

test('makeDefault unsets other defaults in the same team only', function () {
    $team = Team::factory()->create();
    $other = Team::factory()->create();
    $a = AiProviderCredential::factory()->for($team)->create(['is_default' => true]);
    $b = AiProviderCredential::factory()->for($team)->create(['is_default' => false]);
    $foreign = AiProviderCredential::factory()->for($other)->create(['is_default' => true]);

    $b->makeDefault();

    expect($a->fresh()->is_default)->toBeFalse()
        ->and($b->fresh()->is_default)->toBeTrue()
        ->and($foreign->fresh()->is_default)->toBeTrue();
});
