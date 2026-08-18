<div x-data="{ raw: true, showNormalTextarea: false }"
    @compose-preview-toggle.window="raw = !raw"
    @compose-validate.window="$wire.validateCompose().finally(() => $dispatch('compose-validate-finished'))"
    @compose-save.window="$wire.saveEditedCompose()"
    class="flex min-h-0 flex-col gap-3">
    <x-callout type="info" title="Volume names">
        Volume names are prefixed with the service UUID when you save to prevent collisions.
    </x-callout>

    <div class="compose-editor-container min-h-[24rem] overflow-hidden rounded-lg border border-neutral-200 bg-white dark:border-white/[0.10] dark:bg-[#0b0b0c]"
        style="--editor-height: clamp(24rem, calc(100dvh - 25rem), 48rem)">
        <div x-cloak x-show="raw" class="font-mono">
            <div x-cloak x-show="showNormalTextarea">
                <x-forms.textarea class="min-h-[24rem] font-mono" style="height: var(--editor-height)"
                    id="dockerComposeRaw" />
            </div>
            <div x-cloak x-show="!showNormalTextarea">
                <x-forms.textarea allowTab useMonacoEditor monacoEditorLanguage="yaml" id="dockerComposeRaw" />
            </div>
        </div>
        <div x-cloak x-show="raw === false" class="font-mono">
            <x-forms.textarea class="min-h-[24rem] font-mono" style="height: var(--editor-height)" readonly
                id="dockerCompose" />
        </div>
    </div>

    <div
        class="flex flex-wrap items-center gap-2 rounded-lg border border-neutral-200 bg-neutral-50 p-1 dark:border-white/[0.08] dark:bg-white/[0.025]">
        <x-forms.checkbox label="Escape special characters in labels"
            helper="By default, $ (and other characters) is escaped. A $ in a label is saved as $$. Turn this off to use environment variables inside labels."
            id="isContainerLabelEscapeEnabled" instantSave />
        <x-forms.checkbox label="Use plain-text editor" id="showNormalTextarea" x-model="showNormalTextarea" />
    </div>
</div>
