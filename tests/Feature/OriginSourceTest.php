<?php

use App\Livewire\Source\Origin\Change;
use App\Livewire\Source\Origin\Create;
use App\Models\InstanceSettings;
use App\Models\OriginApp;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();

    InstanceSettings::forceCreate([
        'id' => 0,
        'fqdn' => 'https://coolify.example.com',
        'public_ipv4' => null,
        'public_ipv6' => null,
    ]);

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->member = User::factory()->create();
    $this->team->members()->attach($this->owner->id, ['role' => 'owner']);
    $this->team->members()->attach($this->member->id, ['role' => 'member']);

    $this->originApp = OriginApp::factory()->create([
        'name' => 'Origin source',
        'app_id' => 'app_abc123',
        'team_id' => $this->team->id,
    ]);
});

function actAsOriginUser(User $user, Team $team): void
{
    test()->actingAs($user);
    session(['currentTeam' => $team]);
}

function originChange(OriginApp $originApp)
{
    return Livewire::withQueryParams(['origin_app_uuid' => $originApp->uuid])->test(Change::class);
}

describe('Origin source create', function () {
    test('creates an origin app with a key pair for the current team', function () {
        actAsOriginUser($this->owner, $this->team);

        Livewire::test(Create::class)
            ->set('name', 'my-origin')
            ->call('createOriginApp')
            ->assertRedirect();

        $originApp = OriginApp::where('name', 'my-origin')->firstOrFail();

        expect($originApp->team_id)->toBe($this->team->id)
            ->and($originApp->public_key)->toStartWith('-----BEGIN PUBLIC KEY-----')
            ->and($originApp->private_key)->toStartWith('-----BEGIN PRIVATE KEY-----')
            ->and($originApp->app_id)->toBeNull()
            ->and($originApp->api_url)->toBe('https://api.cursor.com/v1/origin');
    });
});

describe('Origin source change', function () {
    test('mount stores the install state for the source and team', function () {
        actAsOriginUser($this->owner, $this->team);

        $state = originChange($this->originApp)->get('installState');

        expect($state)->toHaveLength(64)
            ->and(Cache::get(Change::installStateCacheKey($state)))->toBe([
                'origin_app_id' => $this->originApp->id,
                'team_id' => $this->team->id,
            ]);
    });

    test('install url carries client id, scopes, redirect uri and state', function () {
        actAsOriginUser($this->owner, $this->team);

        $component = originChange($this->originApp);
        $url = $component->instance()->getInstallUrl();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        expect($url)->toStartWith('https://cursor.com/codebase/apps/install?')
            ->and($query['client_id'])->toBe('app_abc123')
            ->and($query['scope'])->toBe(implode(' ', ORIGIN_APP_SCOPES))
            ->and($query['redirect_uri'])->toBe('https://coolify.example.com/webhooks/source/origin/install')
            ->and($query['state'])->toBe($component->get('installState'));
    });

    test('users of another team get 404', function () {
        $otherTeam = Team::factory()->create();
        $otherUser = User::factory()->create();
        $otherTeam->members()->attach($otherUser->id, ['role' => 'owner']);
        actAsOriginUser($otherUser, $otherTeam);

        originChange($this->originApp)->assertNotFound();
    });

    test('members cannot submit, save an installation id or delete', function (string $action) {
        actAsOriginUser($this->member, $this->team);

        originChange($this->originApp)
            ->set('name', 'renamed')
            ->set('installationIdInput', 'i_abc123')
            ->call($action)
            ->assertForbidden();

        expect($this->originApp->fresh())
            ->not->toBeNull()
            ->name->toBe('Origin source')
            ->installation_id->toBeNull();
    })->with(['submit', 'saveInstallationId', 'delete']);

    test('owner can update the name and app id', function () {
        actAsOriginUser($this->owner, $this->team);

        originChange($this->originApp)
            ->set('name', 'renamed')
            ->set('appId', 'app_new456')
            ->call('submit')
            ->assertHasNoErrors();

        expect($this->originApp->fresh())
            ->name->toBe('renamed')
            ->app_id->toBe('app_new456');
    });

    test('rejects an invalid app id', function () {
        actAsOriginUser($this->owner, $this->team);

        originChange($this->originApp)
            ->set('appId', 'not-an-app-id')
            ->call('submit')
            ->assertHasErrors(['appId' => 'regex']);

        expect($this->originApp->fresh()->app_id)->toBe('app_abc123');
    });

    test('saves a verified installation id and namespace', function () {
        Http::fake([
            'https://api.cursor.com/v1/origin/app/installations/*' => Http::response([
                'id' => 'i_abc123',
                'target' => ['slug' => 'acme'],
            ]),
        ]);
        actAsOriginUser($this->owner, $this->team);

        originChange($this->originApp)
            ->set('installationIdInput', 'i_abc123')
            ->call('saveInstallationId')
            ->assertHasNoErrors()
            ->assertSet('isConnected', true);

        expect($this->originApp->fresh())
            ->installation_id->toBe('i_abc123')
            ->namespace_slug->toBe('acme');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.cursor.com/v1/origin/app/installations/i_abc123'
            && str_starts_with($request->header('Authorization')[0] ?? '', 'Bearer '));
    });

    test('does not save an installation id that Origin rejects', function () {
        Http::fake([
            'https://api.cursor.com/v1/origin/app/installations/*' => Http::response(['message' => 'Not found'], 404),
        ]);
        actAsOriginUser($this->owner, $this->team);

        originChange($this->originApp)
            ->set('installationIdInput', 'i_missing')
            ->call('saveInstallationId')
            ->assertDispatched('error');

        expect($this->originApp->fresh()->installation_id)->toBeNull();
    });

    test('rejects an invalid installation id', function () {
        Http::fake();
        actAsOriginUser($this->owner, $this->team);

        originChange($this->originApp)
            ->set('installationIdInput', 'inst-123')
            ->call('saveInstallationId')
            ->assertHasErrors(['installationIdInput' => 'regex']);

        Http::assertNothingSent();
    });
});
