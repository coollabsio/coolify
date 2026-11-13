<?php

use App\Livewire\Project\Shared\EnvironmentVariable\Show;
use App\Models\Application;
use App\Models\EnvironmentVariable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->fx = projectConfigFixture();
    $this->application = Application::factory()->create(['environment_id' => $this->fx['environment']->id, 'destination_id' => $this->fx['destination']->id, 'destination_type' => $this->fx['destination']->getMorphClass()]);
    $this->actingAs($this->fx['user']);
});

function configBadgeRow(Application $application, string $key, ?string $value, ?array $meta)
{
    $variable = EnvironmentVariable::create(['key' => $key, 'value' => $value, 'resourceable_type' => Application::class, 'resourceable_id' => $application->id]);
    $variable->forceFill(['config_meta' => $meta])->saveQuietly();
    // The list loads rows without their values.
    $row = EnvironmentVariable::query()->whereKey($variable->id)
        ->first(['id', 'uuid', 'key', 'comment', 'order', 'is_preview', 'is_multiline', 'is_literal', 'is_runtime', 'is_buildtime', 'is_required', 'is_shown_once', 'is_shared', 'config_meta', 'resourceable_type', 'resourceable_id']);

    return Livewire::test(Show::class, ['env' => $row, 'type' => 'application']);
}

it('marks a file-required variable without a value in the list', function () {
    configBadgeRow($this->application, 'STRIPE_KEY', '', ['form' => 'required'])
        ->assertSee('Needs a value');
});

it('tags a file-managed variable that has its value', function () {
    configBadgeRow($this->application, 'STRIPE_KEY', 'sk_live', ['form' => 'required'])
        ->assertDontSee('Needs a value')
        ->assertSeeHtml('title="Set by coolify.json"');
});

it('does not tag a variable that the file does not manage', function () {
    configBadgeRow($this->application, 'HANDMADE', 'x', null)
        ->assertDontSee('Needs a value')
        ->assertDontSeeHtml('title="Set by coolify.json"');
});
