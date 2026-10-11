<?php

use App\Enums\ProxyTypes;
use App\Livewire\Project\Application\Advanced;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->team->members()->attach($this->owner->id, ['role' => 'owner']);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
    ]);
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $destination = $this->server->standaloneDockers()->firstOrFail();

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://resolver.example.com',
    ]);
    $this->application->settings->update(['is_container_label_readonly_enabled' => true]);
});

function advancedCertificateResolverComponent(Application $application, User $user, Team $team)
{
    test()->actingAs($user);
    session(['currentTeam' => $team]);

    return Livewire::test(Advanced::class, ['application' => $application->fresh()]);
}

test('each certificate resolver mode stores its column value', function (?string $stored, string $mode, ?string $name, ?string $expected) {
    $this->application->settings->update(['traefik_certificate_resolver' => $stored]);

    advancedCertificateResolverComponent($this->application, $this->owner, $this->team)
        ->set('certificateResolverMode', $mode)
        ->set('certificateResolverName', $name)
        ->call('saveCertificateResolver')
        ->assertHasNoErrors()
        ->assertDispatched('success')
        ->assertDispatched('configurationChanged');

    expect($this->application->settings()->first()->traefik_certificate_resolver)->toBe($expected);
})->with([
    'server setting' => ['cloudflare', 'server', null, null],
    'none' => [null, 'none', null, 'none'],
    'custom' => [null, 'custom', 'cloudflare', 'cloudflare'],
    'server setting ignores a leftover name' => ['cloudflare', 'server', 'a.b=c', null],
]);

test('the stored resolver is loaded back into the mode and name', function (?string $stored, string $mode, ?string $name) {
    $this->application->settings->update(['traefik_certificate_resolver' => $stored]);

    advancedCertificateResolverComponent($this->application, $this->owner, $this->team)
        ->assertSet('certificateResolverMode', $mode)
        ->assertSet('certificateResolverName', $name);
})->with([
    'server setting' => [null, 'server', null],
    'none' => ['none', 'none', null],
    'custom' => ['cloudflare', 'custom', 'cloudflare'],
]);

test('saving a custom resolver regenerates the managed labels', function () {
    advancedCertificateResolverComponent($this->application, $this->owner, $this->team)
        ->set('certificateResolverMode', 'custom')
        ->set('certificateResolverName', 'cloudflare')
        ->call('saveCertificateResolver')
        ->assertHasNoErrors();

    $labels = base64_decode($this->application->fresh()->custom_labels);

    expect($labels)->toContain('.tls.certresolver=cloudflare')
        ->and($labels)->not->toContain('certresolver=letsencrypt');
});

test('choosing custom without a name waits for the name instead of saving', function () {
    $this->application->settings->update(['traefik_certificate_resolver' => 'none']);

    advancedCertificateResolverComponent($this->application, $this->owner, $this->team)
        ->set('certificateResolverMode', 'custom')
        ->call('saveCertificateResolverMode')
        ->assertHasNoErrors()
        ->assertNotDispatched('success');

    expect($this->application->settings()->first()->traefik_certificate_resolver)->toBe('none');
});

test('invalid custom resolver names are rejected and nothing is stored', function (?string $name, string $rule) {
    $this->application->settings->update(['traefik_certificate_resolver' => 'letsencrypt']);

    advancedCertificateResolverComponent($this->application, $this->owner, $this->team)
        ->set('certificateResolverMode', 'custom')
        ->set('certificateResolverName', $name)
        ->call('saveCertificateResolver')
        ->assertHasErrors(['certificateResolverName' => [$rule]]);

    expect($this->application->settings()->first()->traefik_certificate_resolver)->toBe('letsencrypt');
})->with([
    'label injection' => ['a.b=c', 'regex'],
    'reserved none' => ['none', 'not_in'],
    'missing name' => [null, 'required'],
]);

test('a team member without update permission cannot change the resolver', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($member->id, ['role' => 'owner']);

    $component = advancedCertificateResolverComponent($this->application, $member, $this->team);
    session(['currentTeam' => $otherTeam]);

    $component->set('certificateResolverMode', 'none')
        ->call('saveCertificateResolver')
        ->assertDispatched('error')
        ->assertNotDispatched('success');

    expect($this->application->settings()->first()->traefik_certificate_resolver)->toBeNull();
});
