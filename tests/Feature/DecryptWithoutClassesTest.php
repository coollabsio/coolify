<?php

use App\Models\InstanceSettings;
use App\Traits\ListensToTeamChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

class DecryptWithoutClassesTestWakeupProbe
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
    DecryptWithoutClassesTestWakeupProbe::$woken = false;
});

it('does not unserialize objects from a Sentinel token', function () {
    $token = encrypt(new DecryptWithoutClassesTestWakeupProbe);

    $this->postJson('/api/v1/sentinel/push', [], ['Authorization' => 'Bearer '.$token])
        ->assertUnauthorized();

    expect(DecryptWithoutClassesTestWakeupProbe::$woken)->toBeFalse();
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

    expect($component->read(['payload' => encrypt(['teamId' => 1, 'probe' => new DecryptWithoutClassesTestWakeupProbe])]))
        ->toHaveKey('teamId', 1)
        ->and(DecryptWithoutClassesTestWakeupProbe::$woken)->toBeFalse();
});
