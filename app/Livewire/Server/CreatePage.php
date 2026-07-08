<?php

namespace App\Livewire\Server;

use App\Models\CloudProviderToken;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class CreatePage extends Component
{
    public ?string $type = null;

    public ?string $token_uuid = null;

    public string $title = 'New Server';

    public ?string $tokenProvider = null;

    public ?string $tokenProviderName = null;

    public bool $hasProviderTokens = false;

    public function mount(?string $type = null, ?string $token_uuid = null): void
    {
        $this->type = $type;
        $this->token_uuid = $token_uuid;
        $this->tokenProvider = match ($type) {
            'hetzner' => 'hetzner',
            'vultr' => 'vultr',
            'digital-ocean' => 'digitalocean',
            default => null,
        };
        $this->tokenProviderName = match ($type) {
            'hetzner' => 'Hetzner',
            'vultr' => 'Vultr',
            'digital-ocean' => 'DigitalOcean',
            default => null,
        };
        $this->hasProviderTokens = $this->tokenProvider
            ? CloudProviderToken::ownedByCurrentTeam()->where('provider', $this->tokenProvider)->exists()
            : false;
        $this->title = match ($type) {
            'hetzner' => 'Hetzner',
            'vultr' => 'Vultr',
            'digital-ocean' => 'DigitalOcean',
            'manual' => 'Manual',
            default => 'New Server',
        };
    }

    public function render(): View
    {
        return view('livewire.server.create-page');
    }
}
