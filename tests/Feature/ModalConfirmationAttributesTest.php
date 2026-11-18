<?php

use App\Models\InstanceSettings;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('does not render canGate and canResource as HTML attributes', function () {
    $team = Team::factory()->create(['name' => 'leaky-team-name-7f3a', 'description' => 'leaky-team-description-7f3a']);

    $html = Blade::render(<<<'BLADE'
        <x-modal-confirmation title="Delete team?" buttonTitle="Delete" submitAction="delete"
            canGate="delete" :canResource="$team"
            class="caller-class" @confirm-old-key-deletion.window="modalOpen = true" />
    BLADE, ['team' => $team]);

    expect($html)
        ->not->toContain('leaky-team-name-7f3a')
        ->not->toContain('leaky-team-description-7f3a')
        ->not->toContain('cangate')
        ->not->toContain('canGate')
        ->not->toContain('canresource')
        ->not->toContain('canResource')
        ->toContain('class="caller-class"')
        ->toContain('@confirm-old-key-deletion.window="modalOpen = true"')
        ->toContain('Delete team?');
});
