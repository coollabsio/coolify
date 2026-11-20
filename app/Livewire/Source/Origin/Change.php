<?php

namespace App\Livewire\Source\Origin;

use App\Models\OriginApp;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Change extends Component
{
    use AuthorizesRequests;

    public const INSTALL_URL = 'https://cursor.com/codebase/apps/install';

    public const APPS_SETTINGS_URL = 'https://cursor.com/codebase/settings/apps';

    public string $webhook_endpoint = '';

    public string $custom_webhook_endpoint = '';

    public bool $use_custom_webhook_endpoint = false;

    public ?string $ipv4 = null;

    public ?string $ipv6 = null;

    public ?string $fqdn = null;

    public ?OriginApp $origin_app = null;

    public string $name = '';

    public ?string $appId = null;

    public ?string $installationId = null;

    public ?string $installationIdInput = null;

    public ?string $namespaceSlug = null;

    public ?string $publicKey = null;

    public bool $isSystemWide = false;

    public bool $isConnected = false;

    public ?string $installState = null;

    public $applications;

    protected function rules(): array
    {
        return [
            'name' => 'required|string',
            'appId' => ['nullable', 'string', 'regex:/^app_[A-Za-z0-9]+$/'],
            'isSystemWide' => 'required|bool',
        ];
    }

    protected function messages(): array
    {
        return [
            'appId.regex' => 'The App ID must look like app_… (letters and digits only).',
            'installationIdInput.regex' => 'The installation ID must look like i_… (letters and digits only).',
        ];
    }

    public function mount()
    {
        try {
            $this->origin_app = OriginApp::where(function ($query) {
                $query->where('team_id', currentTeam()->id)->orWhere('is_system_wide', true);
            })->whereUuid(request()->origin_app_uuid)->firstOrFail();

            $this->applications = $this->origin_app->applications;
            $this->syncData();

            $settings = instanceSettings();
            $this->fqdn = $settings->fqdn;
            if ($settings->public_ipv4) {
                $this->ipv4 = 'http://'.$settings->public_ipv4.':'.config('app.port');
            }
            if ($settings->public_ipv6) {
                $this->ipv6 = 'http://'.formatHostForUrl($settings->public_ipv6).':'.config('app.port');
            }

            if (isCloud() && ! isDev()) {
                $this->webhook_endpoint = config('app.url');
            } else {
                $this->webhook_endpoint = $this->fqdn ?? $this->ipv4 ?? $this->ipv6 ?? config('app.url') ?? '';
            }

            $this->installState = $this->createInstallState();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public static function installStateCacheKey(string $state): string
    {
        return 'origin-app-install-state:'.hash('sha256', $state);
    }

    private function createInstallState(): string
    {
        $state = Str::random(64);

        Cache::put(self::installStateCacheKey($state), [
            'origin_app_id' => $this->origin_app->id,
            'team_id' => currentTeam()->id,
        ], now()->addMinutes(60));

        return $state;
    }

    private function syncData(): void
    {
        $this->name = $this->origin_app->name;
        $this->appId = $this->origin_app->app_id;
        $this->installationId = $this->origin_app->installation_id;
        $this->namespaceSlug = $this->origin_app->namespace_slug;
        $this->publicKey = $this->origin_app->public_key;
        $this->isSystemWide = $this->origin_app->is_system_wide;
        $this->isConnected = $this->origin_app->isConnected();
    }

    public function resolvePublicBaseUrl(): string
    {
        if ($this->use_custom_webhook_endpoint && filled($this->custom_webhook_endpoint)) {
            return rtrim($this->custom_webhook_endpoint, '/');
        }

        return rtrim($this->webhook_endpoint ?: (config('app.url') ?? ''), '/');
    }

    public function webhookUrl(): string
    {
        return $this->resolvePublicBaseUrl().'/webhooks/source/origin/events';
    }

    public function installRedirectUri(): string
    {
        return $this->resolvePublicBaseUrl().'/webhooks/source/origin/install';
    }

    public function isPublicBaseUrlHttps(): bool
    {
        return str_starts_with(strtolower($this->resolvePublicBaseUrl()), 'https://');
    }

    public function getInstallUrl(): string
    {
        $query = http_build_query([
            'client_id' => $this->origin_app->app_id,
            'scope' => implode(' ', ORIGIN_APP_SCOPES),
            'redirect_uri' => $this->installRedirectUri(),
            'state' => $this->installState ??= $this->createInstallState(),
            'summary' => 'Deploy repositories with Coolify',
        ], '', '&', PHP_QUERY_RFC3986);

        return self::INSTALL_URL.'?'.$query;
    }

    public function submit()
    {
        $this->authorize('update', $this->origin_app);

        try {
            $this->appId = filled($this->appId) ? trim($this->appId) : null;
            $this->validate();

            if ($this->origin_app->app_id !== $this->appId) {
                // An installation belongs to one app, so a new App ID invalidates it.
                $this->origin_app->installation_id = null;
                $this->origin_app->namespace_slug = null;
            }

            $this->origin_app->name = $this->name;
            $this->origin_app->app_id = $this->appId;
            if (! isCloud()) {
                $this->origin_app->is_system_wide = $this->isSystemWide;
            }
            $this->origin_app->save();

            $this->syncData();
            $this->dispatch('success', 'Origin App updated.');
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSave()
    {
        $this->authorize('update', $this->origin_app);

        try {
            $this->validateOnly('isSystemWide');

            if (! isCloud()) {
                $this->origin_app->is_system_wide = $this->isSystemWide;
                $this->origin_app->save();
            }
            $this->dispatch('success', 'Origin App updated.');
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function saveInstallationId()
    {
        $this->authorize('update', $this->origin_app);

        try {
            $this->installationIdInput = trim((string) $this->installationIdInput);
            $this->validate([
                'installationIdInput' => ['required', 'string', 'regex:/^i_[A-Za-z0-9]+$/'],
            ]);

            if (blank($this->origin_app->app_id)) {
                $this->dispatch('error', 'Save the App ID before adding an installation ID.');

                return;
            }

            $installation = originApi($this->origin_app, '/app/installations/'.rawurlencode($this->installationIdInput), asApp: true);

            $this->origin_app->installation_id = $this->installationIdInput;
            $this->origin_app->namespace_slug = data_get($installation, 'target.slug');
            $this->origin_app->save();

            $this->installationIdInput = null;
            $this->syncData();
            $this->dispatch('success', 'Origin App installation verified.');
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function testConnection()
    {
        $this->authorize('view', $this->origin_app);

        try {
            if (! $this->origin_app->isConnected()) {
                $this->dispatch('error', 'Origin App is not connected. Install it on Origin first.');

                return;
            }

            $count = count(loadOriginRepositories($this->origin_app));
            $this->dispatch('success', "Connection successful, {$count} ".Str::plural('repository', $count).'.');
        } catch (\Throwable $e) {
            return handleError($e, $this, 'Connection failed:');
        }
    }

    public function regenerateKeyPair()
    {
        $this->authorize('update', $this->origin_app);

        try {
            if ($this->origin_app->isConnected()) {
                $this->dispatch('error', 'The key pair of a connected Origin App cannot be regenerated.');

                return;
            }

            $keyPair = generateOriginKeyPair();
            $this->origin_app->public_key = $keyPair['public_key'];
            $this->origin_app->private_key = $keyPair['private_key'];
            $this->origin_app->save();

            $this->syncData();
            $this->dispatch('success', 'Key pair regenerated. Update the signing key on Origin.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function delete()
    {
        $this->authorize('delete', $this->origin_app);

        try {
            if ($this->origin_app->applications()->exists()) {
                $this->dispatch('error', 'This source is being used by an application. Please delete all applications first.');

                return;
            }
            $this->origin_app->delete();
            // Clear so post-delete re-renders do not run @can / canGate checks against a deleted model.
            $this->origin_app = null;

            return redirectRoute($this, 'source.all');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
