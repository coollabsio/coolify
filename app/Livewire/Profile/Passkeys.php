<?php

namespace App\Livewire\Profile;

use App\Support\PasskeyRelyingParty;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Laravel\Passkeys\Actions\DeletePasskey;
use Livewire\Component;

class Passkeys extends Component
{
    public bool $show_form = false;

    public function mount(): void
    {
        $this->show_form = session('status') === 'passkey-add';
    }

    public function registered(): void
    {
        $this->show_form = false;
        $this->dispatch('success', 'Passkey added.');
    }

    public function rename(int $id, string $name): bool
    {
        abort_unless(instanceSettings()->is_passkey_enabled, 404);

        $passkey = Auth::user()->passkeys()->findOrFail($id);

        $validated = Validator::make(
            ['name' => trim($name)],
            ['name' => ['required', 'string', 'max:255']],
        )->validate();

        $passkey->update(['name' => $validated['name']]);
        $this->dispatch('success', 'Passkey renamed.');

        return true;
    }

    public function delete(int $id, mixed $password = null): mixed
    {
        abort_unless(instanceSettings()->is_passkey_enabled, 404);

        $passkey = Auth::user()->passkeys()->findOrFail($id);

        if (! verifyPasswordConfirmation($password, $this)) {
            return 'The provided password is incorrect.';
        }

        app(DeletePasskey::class)(Auth::user(), $passkey);
        $this->dispatch('success', 'Passkey deleted.');

        return true;
    }

    public function render(): View
    {
        $enabled = (bool) instanceSettings()->is_passkey_enabled;

        return view('livewire.profile.passkeys', [
            'enabled' => $enabled,
            'available' => $enabled && PasskeyRelyingParty::isAvailable(),
            'passkeys' => $enabled ? Auth::user()->passkeys()->latest()->get() : collect(),
        ]);
    }
}
