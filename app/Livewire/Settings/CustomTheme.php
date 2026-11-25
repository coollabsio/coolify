<?php

namespace App\Livewire\Settings;

use App\Rules\ValidCustomTheme;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Validator;
use Livewire\Component;

/**
 * The default Custom theme of the instance. Users without their own theme choice see it.
 */
class CustomTheme extends Component
{
    use AuthorizesRequests;

    public ?string $customTheme = null;

    public function mount(): void
    {
        $this->authorize('view', instanceSettings());
        $this->customTheme = ValidCustomTheme::normalize(instanceSettings()->custom_theme);
    }

    /**
     * Saves the share code, or removes the default theme when the code is null.
     *
     * @return string|null The saved code.
     */
    public function saveCustomTheme(?string $code): ?string
    {
        $settings = instanceSettings();
        $this->authorize('update', $settings);
        Validator::make(['custom_theme' => $code], ['custom_theme' => ['nullable', new ValidCustomTheme]])->validate();

        $this->customTheme = ValidCustomTheme::normalize($code);
        $settings->update(['custom_theme' => $this->customTheme]);
        auditLog('ui.instance.settings.updated', [
            'team_id' => null,
            'resource' => 'instance',
            'section' => 'appearance',
            'changed_fields' => ['custom_theme'],
        ]);
        $this->dispatch('success', $this->customTheme ? 'Default theme saved.' : 'Default theme removed.');

        return $this->customTheme;
    }

    public function render()
    {
        return view('livewire.settings.custom-theme');
    }
}
