<form wire:submit="submit" class="application-settings-form flex flex-col">
    <x-unsaved-bar action="submit" />
    <x-application.settings-section id="preview-template-section" title="Preview URL template"
        helper="Define how Coolify generates domains for pull request deployments.">
        <x-slot:actions>
            @can('update', $application)
                <x-forms.button type="button" wire:click="resetToDefault">
                    Reset template
                </x-forms.button>
            @endcan
        </x-slot:actions>

        <x-forms.input id="previewUrlTemplate" label="URL template"
            helper="Use @@{{ random }} for a random subdomain, @@{{ pr_id }} for the pull request number, or @@{{ domain }} for the application domain."
            canGate="update" :canResource="$application" />

        @if ($previewUrlTemplate)
            <div
                class="mt-4 flex items-center justify-between gap-3 rounded-lg bg-neutral-100 px-3 py-2.5 ring-1 ring-neutral-200 dark:bg-white/[0.04] dark:ring-white/[0.07]">
                <span class="text-[13px] text-neutral-500 dark:text-fg-dim">Generated pattern</span>
                <code class="break-all text-right font-mono text-xs text-neutral-700 dark:text-fg">
                    {{ $previewUrlTemplate }}
                </code>
            </div>
        @endif
    </x-application.settings-section>
</form>
