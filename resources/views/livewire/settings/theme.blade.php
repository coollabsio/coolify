<div>
    <x-slot:title>
        Theme Settings | Coolify
    </x-slot>

    <x-settings.layout>
        <form wire:submit="submit" class="application-settings-form flex min-w-0 flex-col gap-6">
            <x-unsaved-bar action="submit" targets="theme_preset,custom_css" />

            <x-application.settings-section title="Theme"
                helper="Applies to everyone on this instance in Dark, System (dark) and Custom appearance. Light appearance is not affected. Saving reloads the page.">
                <div class="flex flex-col gap-4">
                    <div class="max-w-md">
                        <x-forms.listbox id="theme_preset" :live="true" :options="$options" />
                    </div>

                    @if ($theme_preset === 'custom')
                        <div class="flex flex-col gap-2">
                            <x-forms.textarea id="custom_css" rows="16" label="Custom CSS or iTerm2 colour scheme"
                                helper="Override the semantic variables (--color-app, --color-panel, --color-surface, --color-fg, --color-accent, …) rather than component classes. Scope rules to html.dark to leave Light appearance alone."
                                placeholder="html.dark { --color-accent: #7aa2f7; --color-app: #16161e; }" />
                            <p class="text-xs text-neutral-500 dark:text-neutral-400">
                                Paste plain CSS (served as-is, no Tailwind directives), <strong>or an iTerm2 <code>.itermcolors</code> file</strong> (XML plist).
                                iTerm2 schemes are converted to CSS when you save, so you can tweak the result afterwards.
                            </p>
                        </div>
                    @endif

                    <div class="flex items-center gap-3">
                        <x-forms.button type="submit">Save</x-forms.button>
                        @if ($theme_preset !== '' && $theme_preset !== 'custom')
                            <span class="text-xs text-neutral-500">Tip: pick “Custom CSS…” to start from this preset — the file is in <code>resources/themes/{{ $theme_preset }}.css</code>.</span>
                        @endif
                    </div>
                </div>
            </x-application.settings-section>
        </form>
    </x-settings.layout>
</div>
