<?php

namespace App\Livewire\Settings;

use App\Models\InstanceSettings;
use App\Rules\ValidDnsServers;
use App\Rules\ValidIpOrCidr;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Advanced extends Component
{
    use AuthorizesRequests;

    public InstanceSettings $settings;

    #[Validate('boolean')]
    public bool $is_registration_enabled;

    #[Validate('boolean')]
    public bool $do_not_track;

    #[Validate('boolean')]
    public bool $is_dns_validation_enabled;

    public ?string $custom_dns_servers = null;

    #[Validate('boolean')]
    public bool $is_api_enabled;

    public ?string $allowed_ips = null;

    #[Validate('boolean')]
    public bool $is_sponsorship_popup_enabled;

    #[Validate('boolean')]
    public bool $disable_two_step_confirmation;

    #[Validate('boolean')]
    public bool $is_wire_navigate_enabled;

    #[Validate('boolean')]
    public bool $is_mcp_server_enabled;

    public ?string $webhook_allowed_internal_hosts = null;

    #[Validate('boolean')]
    public bool $webhook_allow_localhost;

    public function rules()
    {
        return [
            'is_registration_enabled' => 'boolean',
            'do_not_track' => 'boolean',
            'is_dns_validation_enabled' => 'boolean',
            'custom_dns_servers' => ['nullable', 'string', new ValidDnsServers],
            'is_api_enabled' => 'boolean',
            'allowed_ips' => ['nullable', 'string', new ValidIpOrCidr],
            'is_sponsorship_popup_enabled' => 'boolean',
            'disable_two_step_confirmation' => 'boolean',
            'is_wire_navigate_enabled' => 'boolean',
            'is_mcp_server_enabled' => 'boolean',
            'webhook_allowed_internal_hosts' => 'nullable|string',
            'webhook_allow_localhost' => 'boolean',
        ];
    }

    public function mount()
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }
        $this->settings = instanceSettings();
        $this->custom_dns_servers = $this->settings->custom_dns_servers;
        $this->allowed_ips = $this->settings->allowed_ips;
        $this->do_not_track = $this->settings->do_not_track;
        $this->is_registration_enabled = $this->settings->is_registration_enabled;
        $this->is_dns_validation_enabled = $this->settings->is_dns_validation_enabled;
        $this->is_api_enabled = $this->settings->is_api_enabled;
        $this->disable_two_step_confirmation = $this->settings->disable_two_step_confirmation;
        $this->is_sponsorship_popup_enabled = $this->settings->is_sponsorship_popup_enabled;
        $this->is_wire_navigate_enabled = $this->settings->is_wire_navigate_enabled ?? true;
        $this->is_mcp_server_enabled = $this->settings->is_mcp_server_enabled ?? false;
        $this->webhook_allowed_internal_hosts = collect($this->settings->webhook_allowed_internal_hosts ?? [])->implode(',');
        $this->webhook_allow_localhost = $this->settings->webhook_allow_localhost ?? false;
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->settings);
            $this->validate();

            $this->custom_dns_servers = str($this->custom_dns_servers)->replaceEnd(',', '')->trim();
            $this->custom_dns_servers = str($this->custom_dns_servers)->trim()->explode(',')->map(function ($dns) {
                return str($dns)->trim()->lower();
            })->unique()->implode(',');

            // Handle allowed IPs with subnet support and 0.0.0.0 special case
            $this->allowed_ips = str($this->allowed_ips)->replaceEnd(',', '')->trim();

            // Only validate and clean up if we have IPs and it's not 0.0.0.0 (allow all)
            if (! empty($this->allowed_ips) && ! in_array('0.0.0.0', array_map('trim', explode(',', $this->allowed_ips)))) {
                $invalidEntries = [];
                $validEntries = str($this->allowed_ips)->trim()->explode(',')->map(function ($entry) use (&$invalidEntries) {
                    $entry = str($entry)->trim()->toString();

                    if (empty($entry)) {
                        return null;
                    }

                    // Check if it's valid CIDR notation
                    if (str_contains($entry, '/')) {
                        [$ip, $mask] = explode('/', $entry);
                        $isIpv6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
                        $maxMask = $isIpv6 ? 128 : 32;
                        if (filter_var($ip, FILTER_VALIDATE_IP) && is_numeric($mask) && $mask >= 0 && $mask <= $maxMask) {
                            return $entry;
                        }
                        $invalidEntries[] = $entry;

                        return null;
                    }

                    // Check if it's a valid IP address
                    if (filter_var($entry, FILTER_VALIDATE_IP)) {
                        return $entry;
                    }

                    $invalidEntries[] = $entry;

                    return null;
                })->filter()->values()->all();

                if (! empty($invalidEntries)) {
                    $this->dispatch('error', 'Invalid IP addresses or subnets: '.implode(', ', $invalidEntries));

                    return;
                }

                if (empty($validEntries)) {
                    $this->dispatch('error', 'No valid IP addresses or subnets provided');

                    return;
                }

                $validEntries = deduplicateAllowlist($validEntries);

                $this->allowed_ips = implode(',', $validEntries);
            }

            $webhookAllowedInternalHosts = $this->normalizeWebhookAllowedInternalHosts();
            if ($webhookAllowedInternalHosts === false) {
                return;
            }

            $this->instantSave($webhookAllowedInternalHosts);
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    /**
     * @param  array<int, string>|null  $webhookAllowedInternalHosts
     */
    public function instantSave(?array $webhookAllowedInternalHosts = null)
    {
        try {
            $this->authorize('update', $this->settings);
            $this->settings->is_registration_enabled = $this->is_registration_enabled;
            $this->settings->do_not_track = $this->do_not_track;
            $this->settings->is_dns_validation_enabled = $this->is_dns_validation_enabled;
            $this->settings->custom_dns_servers = $this->custom_dns_servers;
            $this->settings->is_api_enabled = $this->is_api_enabled;
            $this->settings->allowed_ips = $this->allowed_ips;
            $this->settings->is_sponsorship_popup_enabled = $this->is_sponsorship_popup_enabled;
            $this->settings->disable_two_step_confirmation = $this->disable_two_step_confirmation;
            $this->settings->is_wire_navigate_enabled = $this->is_wire_navigate_enabled;
            $this->settings->is_mcp_server_enabled = $this->is_mcp_server_enabled;
            $this->settings->webhook_allowed_internal_hosts = $webhookAllowedInternalHosts ?? $this->settings->webhook_allowed_internal_hosts ?? [];
            $this->settings->webhook_allow_localhost = $this->webhook_allow_localhost;
            $this->settings->save();
            $this->dispatch('success', 'Settings updated!');
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    /**
     * @return array<int, string>|false
     */
    private function normalizeWebhookAllowedInternalHosts(): array|false
    {
        $entries = collect(preg_split('/[,\r\n]+/', $this->webhook_allowed_internal_hosts ?? '') ?: [])
            ->map(fn (string $entry): string => rtrim(strtolower(trim($entry)), '.'))
            ->filter()
            ->unique()
            ->values();

        $invalidEntries = $entries->reject(fn (string $entry): bool => $this->isValidWebhookAllowlistEntry($entry));
        if ($invalidEntries->isNotEmpty()) {
            $this->dispatch('error', 'Invalid webhook internal allowlist entries: '.$invalidEntries->implode(', '));

            return false;
        }

        $this->webhook_allowed_internal_hosts = $entries->implode(',');

        return $entries->all();
    }

    private function isValidWebhookAllowlistEntry(string $entry): bool
    {
        if (filter_var($entry, FILTER_VALIDATE_IP)) {
            return true;
        }

        if (str_contains($entry, '/')) {
            [$ip, $mask] = array_pad(explode('/', $entry, 2), 2, null);
            $isIpv6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
            $maxMask = $isIpv6 ? 128 : 32;

            return filter_var($ip, FILTER_VALIDATE_IP) !== false
                && is_numeric($mask)
                && (int) $mask >= 0
                && (int) $mask <= $maxMask;
        }

        return filter_var($entry, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    public function toggleRegistration($password): bool
    {
        if (! verifyPasswordConfirmation($password, $this)) {
            return false;
        }

        $this->settings->is_registration_enabled = $this->is_registration_enabled = true;
        $this->settings->save();
        $this->dispatch('success', 'Registration has been enabled.');

        return true;
    }

    public function toggleTwoStepConfirmation($password): bool
    {
        $this->authorize('update', $this->settings);
        if (! verifyPasswordConfirmation($password, $this)) {
            return false;
        }

        $this->settings->disable_two_step_confirmation = $this->disable_two_step_confirmation = true;
        $this->settings->save();
        $this->dispatch('success', 'Two step confirmation has been disabled.');

        return true;
    }

    public function render()
    {
        return view('livewire.settings.advanced');
    }
}
