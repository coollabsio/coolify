<div x-data="instanceThemeControls($wire)">
    <x-application.settings-section id="default-theme-section" title="Default theme"
        description="The Custom theme for users who did not choose their own theme in Appearance.">
        <div class="flex flex-wrap items-center gap-4">
            <template x-if="customTheme">
                <x-theme-controls.swatch theme="customTheme" class="h-12 w-24 shrink-0" />
            </template>
            <p class="min-w-0 flex-1 text-sm text-neutral-600 dark:text-fg-dim">
                @if ($customTheme)
                    <span class="font-mono">{{ $customTheme }}</span>
                @else
                    No default theme. Users see the built-in Dark theme until they choose one.
                @endif
            </p>
            @can('update', instanceSettings())
                <x-forms.button type="button" @click="openCustomDialog()">Edit default theme</x-forms.button>
            @endcan
        </div>
        @error('custom_theme')
            <p class="mt-2 text-xs text-error">{{ $message }}</p>
        @enderror
    </x-application.settings-section>

    <x-theme-controls.custom-dialog
        description="The default colors of this instance. Select Coolify to remove the default theme." />
</div>
