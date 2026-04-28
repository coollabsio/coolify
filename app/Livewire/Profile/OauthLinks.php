<?php

namespace App\Livewire\Profile;

use App\Models\OauthSetting;
use App\Models\OauthUserLink;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class OauthLinks extends Component
{
    public array $providers = [];

    public function mount(): void
    {
        $this->refreshProviders();
    }

    public function connect(string $provider)
    {
        if (! Auth::check()) {
            abort(403);
        }

        $setting = OauthSetting::where('provider', $provider)->where('enabled', true)->first();
        if (! $setting) {
            $this->dispatch('error', 'OAuth provider is not enabled.');

            return null;
        }

        session()->put('oauth.intent', 'link');
        session()->put('oauth.user_id', Auth::id());

        return redirect()->route('auth.redirect', ['provider' => $provider]);
    }

    public function disconnect(int $linkId): void
    {
        $link = OauthUserLink::where('id', $linkId)
            ->where('user_id', Auth::id())
            ->first();

        if (! $link) {
            $this->dispatch('error', 'OAuth link not found.');

            return;
        }

        $provider = $link->provider;
        $link->delete();

        $this->refreshProviders();
        $this->dispatch('success', "Disconnected {$provider}.");
    }

    public function render()
    {
        $this->refreshProviders();

        return view('livewire.profile.oauth-links');
    }

    private function refreshProviders(): void
    {
        $enabled = OauthSetting::where('enabled', true)->get();
        $userLinks = OauthUserLink::where('user_id', Auth::id())->get()->keyBy('provider');

        $this->providers = $enabled->map(function (OauthSetting $setting) use ($userLinks) {
            $link = $userLinks->get($setting->provider);

            return [
                'provider' => $setting->provider,
                'linked' => (bool) $link,
                'link_id' => $link?->id,
                'provider_user_id' => $link?->provider_user_id,
            ];
        })->values()->toArray();
    }
}
