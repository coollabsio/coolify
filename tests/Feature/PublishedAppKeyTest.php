<?php

use App\Http\Middleware\RejectPublishedAppKey;
use App\Models\InstanceSettings;
use App\Traits\ListensToTeamChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

class PublishedAppKeyTestWakeupProbe
{
    public static bool $woken = false;

    public function __wakeup(): void
    {
        self::$woken = true;
    }
}

beforeEach(function () {
    config(['app.maintenance.store' => 'array']);
    InstanceSettings::forceCreate(['id' => 0]);
    PublishedAppKeyTestWakeupProbe::$woken = false;
});

it('refuses HTTP requests when a published key is the current or a previous key', function (array $config) {
    config($config);

    $this->get('/api/health')
        ->assertStatus(503)
        ->assertSee('app-key:reencrypt');
})->with([
    'current key' => [['app.key' => RejectPublishedAppKey::PUBLISHED_KEYS[0]]],
    'previous key' => [['app.previous_keys' => [RejectPublishedAppKey::PUBLISHED_KEYS[0]]]],
]);

it('serves HTTP requests with a private key', function () {
    $this->get('/api/health')->assertOk();
});

it('does not unserialize objects from a Sentinel token', function () {
    $token = encrypt(new PublishedAppKeyTestWakeupProbe);

    $this->postJson('/api/v1/sentinel/push', [], ['Authorization' => 'Bearer '.$token])
        ->assertUnauthorized();

    expect(PublishedAppKeyTestWakeupProbe::$woken)->toBeFalse();
});

it('does not unserialize objects from a team channel payload', function () {
    $component = new class
    {
        use ListensToTeamChannel;

        public function read(array $event): array
        {
            return $this->teamChannelPayload($event);
        }
    };

    expect($component->read(['payload' => encrypt(['teamId' => 1, 'probe' => new PublishedAppKeyTestWakeupProbe])]))
        ->toHaveKey('teamId', 1)
        ->and(PublishedAppKeyTestWakeupProbe::$woken)->toBeFalse();
});
