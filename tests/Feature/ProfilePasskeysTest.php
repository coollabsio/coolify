<?php

use App\Livewire\Profile\Passkeys;
use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Once;
use Laravel\Passkeys\Passkey;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();
    $this->withoutVite();

    $this->user = User::factory()->create(['name' => 'Passkey User', 'password' => Hash::make('secret-password')]);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->user->teams()->sole()]);
});

function profilePasskey(User $user, string $name = 'Laptop'): Passkey
{
    return $user->passkeys()->create([
        'name' => $name,
        'credential_id' => 'credential-'.$user->id.'-'.$name,
        'credential' => ['aaguid' => '00000000-0000-0000-0000-000000000000'],
    ]);
}

it('lists only the passkeys of the current user', function () {
    profilePasskey($this->user, 'My laptop');
    profilePasskey(User::factory()->create(), 'Other phone');

    Livewire::test(Passkeys::class)
        ->assertSee('My laptop')
        ->assertDontSee('Other phone');
});

it('opens the add form only after the confirm redirect', function () {
    Livewire::test(Passkeys::class)->assertSet('show_form', false);

    session()->flash('status', 'passkey-add');

    Livewire::test(Passkeys::class)->assertSet('show_form', true);
});

it('renames a passkey of the current user', function () {
    $passkey = profilePasskey($this->user);

    Livewire::test(Passkeys::class)->call('rename', $passkey->id, '  Work laptop  ');

    expect($passkey->fresh()->name)->toBe('Work laptop');
});

it('rejects an empty or too long passkey name', function (string $name) {
    $passkey = profilePasskey($this->user);

    Livewire::test(Passkeys::class)->call('rename', $passkey->id, $name)->assertHasErrors('name');

    expect($passkey->fresh()->name)->toBe('Laptop');
})->with(['empty' => ['   '], 'too long' => [str_repeat('a', 256)]]);

it('does not rename the passkey of another user', function () {
    $passkey = profilePasskey(User::factory()->create());

    expect(fn () => Livewire::test(Passkeys::class)->call('rename', $passkey->id, 'Stolen'))
        ->toThrow(ModelNotFoundException::class);

    expect($passkey->fresh()->name)->toBe('Laptop');
});

it('deletes a passkey with the correct password', function () {
    $passkey = profilePasskey($this->user);

    Livewire::test(Passkeys::class)->call('delete', $passkey->id, 'secret-password');

    expect(Passkey::find($passkey->id))->toBeNull();
});

it('does not delete a passkey with a wrong password', function () {
    $passkey = profilePasskey($this->user);

    Livewire::test(Passkeys::class)->call('delete', $passkey->id, 'wrong-password');

    expect(Passkey::find($passkey->id))->not->toBeNull();
});

it('does not delete the passkey of another user', function () {
    $passkey = profilePasskey(User::factory()->create());

    expect(fn () => Livewire::test(Passkeys::class)->call('delete', $passkey->id, 'secret-password'))
        ->toThrow(ModelNotFoundException::class);

    expect(Passkey::find($passkey->id))->not->toBeNull();
});

it('renders nothing when passkeys are turned off', function () {
    InstanceSettings::query()->where('id', 0)->update(['is_passkey_enabled' => false]);
    Once::flush();
    profilePasskey($this->user, 'My laptop');

    Livewire::test(Passkeys::class)->assertDontSee('My laptop')->assertDontSee('Passkeys');
});

it('refuses passkey changes when passkeys are turned off', function (string $action, array $arguments) {
    $passkey = profilePasskey($this->user);
    InstanceSettings::query()->where('id', 0)->update(['is_passkey_enabled' => false]);
    Once::flush();

    Livewire::test(Passkeys::class)->call($action, $passkey->id, ...$arguments)->assertNotFound();

    expect($passkey->fresh()?->name)->toBe('Laptop');
})->with([
    'rename' => ['rename', ['Renamed']],
    'delete' => ['delete', ['secret-password']],
]);

it('explains why a passkey cannot be added on an address that does not support passkeys', function () {
    profilePasskey($this->user, 'My laptop');

    $this->get('http://203.0.113.10:8000/profile')
        ->assertOk()
        ->assertSee('My laptop')
        ->assertSee('Passkeys need HTTPS')
        ->assertDontSee('Add passkey');
});

it('reports if a rename was saved', function () {
    $passkey = profilePasskey($this->user);

    Livewire::test(Passkeys::class)->call('rename', $passkey->id, 'Work laptop')->assertReturned(true);
    Livewire::test(Passkeys::class)->call('rename', $passkey->id, '   ')->assertReturned(null);
});
