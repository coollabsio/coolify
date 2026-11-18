<?php

namespace App\Livewire\Source\Origin;

use App\Models\OriginApp;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Create extends Component
{
    use AuthorizesRequests;

    public string $name;

    public bool $is_system_wide = false;

    public function mount(): void
    {
        $this->name = substr(generate_random_name(), 0, 30);
    }

    public function createOriginApp()
    {
        try {
            $this->authorize('createAnyResource');

            $this->validate([
                'name' => 'required|string',
                'is_system_wide' => 'required|bool',
            ]);

            $keyPair = generateOriginKeyPair();

            $origin_app = OriginApp::create([
                'name' => $this->name,
                'public_key' => $keyPair['public_key'],
                'private_key' => $keyPair['private_key'],
                'is_system_wide' => isCloud() ? false : $this->is_system_wide,
                'team_id' => currentTeam()->id,
            ]);

            if (session('from')) {
                session(['from' => session('from') + ['source_id' => $origin_app->id]]);
            }

            return redirectRoute($this, 'source.origin.show', ['origin_app_uuid' => $origin_app->uuid]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
