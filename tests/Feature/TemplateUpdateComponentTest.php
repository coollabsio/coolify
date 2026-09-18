<?php

use App\Livewire\Project\Service\TemplateUpdate;
use App\Livewire\Project\Service\TemplateUpdateBanner;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\TemplateUpdateChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->actingAs($this->user);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $this->server->id]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $compose = "services:\n  app:\n    image: nginx:2\n";
    Cache::flush();
    Cache::forever('coolify:service-templates-bundle', [
        'fetched_at' => now()->toIso8601String(),
        'json' => json_encode(['demo' => ['compose' => base64_encode($compose), 'envs' => base64_encode('NEWKEY=hi')]]),
    ]);
});

function makeDemoService(): Service
{
    return Service::factory()->create([
        'service_type' => 'demo',
        'environment_id' => test()->environment->id,
        'server_id' => test()->server->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
        'docker_compose_raw' => "services:\n  app:\n    image: nginx:1\n",
        'compose_parsing_version' => '5',
        'template_reference_hash' => 'stale',
    ]);
}

it('renders update-available state and applies the compose update', function () {
    $service = makeDemoService();

    Livewire::test(TemplateUpdate::class, ['service' => $service])
        ->assertSee('Update available')
        ->set('acceptedHunks', [0 => true])
        ->call('apply');

    $service->refresh();
    expect($service->docker_compose_raw)->toContain('nginx:2');
    expect($service->docker_compose_raw)->not->toContain('nginx:1');
    expect($service->template_reference_hash)->toBe(TemplateUpdateChecker::currentHash('demo'));
    expect($service->template_dismissed_hash)->toBeNull();
});

it('flags the demo service so the page banner and tab dot show', function () {
    $service = makeDemoService();
    expect(TemplateUpdateChecker::showBadge($service->refresh()))->toBeTrue();
});

it('renders the diff chrome without stray entities', function () {
    $service = makeDemoService();

    Livewire::test(TemplateUpdate::class, ['service' => $service])
        ->assertSee('Review changes')
        ->assertSee('Edit compose')
        ->assertSee('image: nginx:2')
        ->assertDontSee('&quot;');
});

it('shows the banner with a dismiss control and dismissing hides it', function () {
    $service = makeDemoService();

    $component = Livewire::test(TemplateUpdateBanner::class, ['service' => $service, 'href' => '/template'])
        ->assertSee('Review changes')
        ->assertSee('is available')
        ->call('dismiss')
        ->assertDontSee('Review changes');

    $service->refresh();
    expect($service->template_dismissed_hash)->toBe(TemplateUpdateChecker::currentHash('demo'));
    expect(TemplateUpdateChecker::showBadge($service))->toBeFalse();
});

it('seeds the inline editor from the current selection when switching to edit mode', function () {
    $service = makeDemoService();

    Livewire::test(TemplateUpdate::class, ['service' => $service])
        ->set('acceptedHunks', [0 => true])
        ->call('setMode', 'edit')
        ->assertSet('mode', 'edit')
        ->assertSet('editorContent', "services:\n  app:\n    image: nginx:2\n");
});

it('seeds the inline editor from the latest template when no hunks are selected', function () {
    $service = makeDemoService();

    Livewire::test(TemplateUpdate::class, ['service' => $service])
        ->call('setMode', 'edit')
        ->assertSet('mode', 'edit')
        ->assertSet('editorContent', "services:\n  app:\n    image: nginx:2\n")
        ->assertSee('Apply compose')
        ->assertSee('Reset to latest template');
});

it('gates the apply buttons on a valid hunk selection', function () {
    $service = makeDemoService();

    $component = Livewire::test(TemplateUpdate::class, ['service' => $service]);
    // A diff exists, so "Replace with latest" is enabled but "Apply selected" is not yet.
    expect($component->instance()->hasHunks)->toBeTrue();
    expect($component->instance()->hasSelectedHunks)->toBeFalse();
    // The disabled button carries a reason tooltip instead of a stray text line.
    // (Visibility is gated client-side on the live disabled state.)
    $component->assertSee('Select at least one change to apply.');

    $component->set('acceptedHunks', [0 => true]);
    expect($component->instance()->hasSelectedHunks)->toBeTrue();
});

it('does not touch the compose when apply runs with no selected hunks', function () {
    $service = makeDemoService();
    $original = $service->docker_compose_raw;

    Livewire::test(TemplateUpdate::class, ['service' => $service])
        ->set('acceptedHunks', [])
        ->call('apply');

    $service->refresh();
    expect($service->docker_compose_raw)->toBe($original);
    expect($service->template_reference_hash)->toBe('stale');
});

it('re-seeds the editor when the hunk selection changed since the last edit', function () {
    $service = makeDemoService();

    Livewire::test(TemplateUpdate::class, ['service' => $service])
        ->call('setMode', 'edit')
        ->set('editorContent', 'MANUAL EDIT')
        ->call('setMode', 'review')
        ->set('acceptedHunks', [0 => true])
        ->call('setMode', 'edit')
        ->assertSet('editorContent', "services:\n  app:\n    image: nginx:2\n");
});

it('preserves manual editor edits when the selection is unchanged', function () {
    $service = makeDemoService();

    Livewire::test(TemplateUpdate::class, ['service' => $service])
        ->call('setMode', 'edit')
        ->set('editorContent', 'MANUAL EDIT')
        ->call('setMode', 'review')
        ->call('setMode', 'edit')
        ->assertSet('editorContent', 'MANUAL EDIT');
});

it('renders the original compose in the diff editor without double-escaping quotes', function () {
    $service = makeDemoService();
    $service->forceFill(['docker_compose_raw' => "services:\n  app:\n    image: 'nginx:1'\n"])->save();

    $html = Livewire::test(TemplateUpdate::class, ['service' => $service])
        ->call('setMode', 'edit')
        ->html();

    // The single quote must reach the editor single-escaped, never as &amp;#039;.
    expect($html)->toContain('&#039;');
    expect($html)->not->toContain('&amp;#039;');
});

it('applies hand-edited compose from the inline editor', function () {
    $service = makeDemoService();

    Livewire::test(TemplateUpdate::class, ['service' => $service])
        ->call('setMode', 'edit')
        ->set('editorContent', "services:\n  app:\n    image: nginx:3-custom\n")
        ->call('applyEditor');

    $service->refresh();
    expect($service->docker_compose_raw)->toContain('nginx:3-custom');
    expect($service->template_reference_hash)->toBe(TemplateUpdateChecker::currentHash('demo'));
});

it('generates magic env values for new SERVICE_ keys introduced by an applied template', function () {
    $service = makeDemoService();

    Livewire::test(TemplateUpdate::class, ['service' => $service])
        ->call('setMode', 'edit')
        ->set('editorContent', "services:\n  app:\n    image: nginx:2\n    environment:\n      - 'SECRET=\${SERVICE_PASSWORD_APPLYGEN}'\n")
        ->call('applyEditor');

    $generated = $service->environment_variables()->where('key', 'SERVICE_PASSWORD_APPLYGEN')->first();
    expect($generated)->not->toBeNull();
    expect($generated->value)->not->toBe('');
});

it('rejects invalid yaml from the inline editor without saving', function () {
    $service = makeDemoService();
    $original = $service->docker_compose_raw;

    Livewire::test(TemplateUpdate::class, ['service' => $service])
        ->call('setMode', 'edit')
        ->set('editorContent', "services:\n  app:\n   image: [unclosed\n")
        ->call('applyEditor')
        ->assertDispatched('error');

    $service->refresh();
    expect($service->docker_compose_raw)->toBe($original);
});

it('dismisses the current version so the badge is suppressed', function () {
    $service = makeDemoService();

    Livewire::test(TemplateUpdate::class, ['service' => $service])
        ->call('dismiss');

    $service->refresh();
    expect($service->template_dismissed_hash)->toBe(TemplateUpdateChecker::currentHash('demo'));
    expect(TemplateUpdateChecker::showBadge($service))->toBeFalse();
});
