<?php

use App\Livewire\Server\LogDrains;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);
});

it('does not require custom Fluent Bit config in the browser while the drain is disabled', function () {
    $html = Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])->html();
    $document = new DOMDocument;
    $previousState = libxml_use_internal_errors(true);
    $document->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($previousState);

    $textarea = (new DOMXPath($document))
        ->query('//textarea[@*[name()="wire:model" and .="logDrainCustomConfig"]]')
        ?->item(0);

    expect($textarea)->toBeInstanceOf(DOMElement::class)
        ->and($textarea->hasAttribute('required'))->toBeFalse();
});

it('allows clearing custom Fluent Bit config while the drain is disabled', function () {
    $this->server->settings()->update([
        'is_logdrain_custom_enabled' => false,
        'logdrain_custom_config' => "[OUTPUT]\n    Name http",
    ]);

    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->set('logDrainCustomConfig', null)
        ->call('submit', 'custom')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    expect($this->server->settings()->value('logdrain_custom_config'))->toBeNull();
});

it('requires custom Fluent Bit config when enabling the drain', function () {
    Livewire::test(LogDrains::class, ['server_uuid' => $this->server->uuid])
        ->set('isLogDrainCustomEnabled', true)
        ->set('logDrainCustomConfig', null)
        ->call('customValidation')
        ->assertHasErrors(['logDrainCustomConfig' => ['required']]);
});
