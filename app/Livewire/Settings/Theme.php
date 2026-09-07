<?php

namespace App\Livewire\Settings;

use App\Models\InstanceSettings;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Theme extends Component
{
    use AuthorizesRequests;

    public InstanceSettings $settings;

    public string $theme_preset = '';

    public ?string $custom_css = null;

    public function mount()
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }
        $this->settings = instanceSettings();
        $this->theme_preset = $this->settings->theme_preset ?? '';
        $this->custom_css = $this->settings->custom_css;
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->settings);
            $this->validate([
                'theme_preset' => ['nullable', 'string', 'in:custom,'.implode(',', array_keys(themePresets()))],
                'custom_css' => ['nullable', 'string', 'max:200000'],
            ]);
            if ($this->theme_preset === 'custom' && ($converted = itermColorsToCss((string) $this->custom_css)) !== null) {
                $this->custom_css = $converted; // show the generated CSS so it can be tweaked
            }
            $this->settings->theme_preset = $this->theme_preset ?: null;
            $this->settings->custom_css = $this->custom_css;
            $this->settings->save();
            $this->dispatch('success', 'Theme saved. Reloading…');
            $this->dispatch('reloadWindow', 800);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.settings.theme', [
            'options' => collect([['value' => '', 'label' => 'Default (Coolify)']])
                ->concat(collect(themePresets())->map(fn ($label, $slug) => ['value' => $slug, 'label' => $label])->values())
                ->push(['value' => 'custom', 'label' => 'Custom CSS…'])
                ->all(),
        ]);
    }
}
