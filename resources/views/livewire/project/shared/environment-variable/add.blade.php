<form class="flex w-full flex-col gap-4" wire:submit='submit'
    x-data="{ isMultiline: $wire.entangle('is_multiline') }">
    <div class="grid gap-4 sm:grid-cols-2">
        <x-forms.input placeholder="NODE_ENV" id="key" label="Name" required />
        <x-forms.input id="comment" label="Comment" placeholder="Optional note"
            helper="Add a note to document what this environment variable is used for." maxlength="256" />
    </div>
    <div>
        <template x-if="isMultiline">
            <div wire:key="env-value-textarea">
                <x-forms.textarea id="value" label="Value" required class="font-sans" spellcheck />
            </div>
        </template>
        <template x-if="!isMultiline">
            <div wire:key="env-value-input">
                <x-forms.env-var-input placeholder="production" id="value" label="Value" required
                    :availableVars="$shared ? [] : $this->availableSharedVariables"
                    :projectUuid="data_get($parameters, 'project_uuid')"
                    :environmentUuid="data_get($parameters, 'environment_uuid')"
                    :serverUuid="data_get($parameters, 'server_uuid')" />
            </div>
        </template>
        @if (!$shared)
            <div x-cloak x-show="!isMultiline" wire:key="env-value-tip"
                class="mt-1.5 text-xs text-neutral-500 dark:text-fg-faint">
                Tip: Type <span class="font-mono text-coollabs dark:text-warning">{{ '{{' }}</span> to reference a
                shared environment variable
            </div>
        @endif
    </div>

    <div class="grid gap-4 border-t border-neutral-200 pt-4 dark:border-white/[0.07] sm:grid-cols-2">
        <x-forms.listbox id="is_multiline" label="Value type" :live="true" :options="[
            ['value' => false, 'label' => 'Single line'],
            ['value' => true, 'label' => 'Multiline'],
        ]" />
        @if (!$shared)
            <x-forms.listbox id="is_literal" label="Interpolation" :options="[
                ['value' => false, 'label' => 'Interpolate $VARIABLES'],
                ['value' => true, 'label' => 'Literal (keep $ characters as-is)'],
            ]"
                helper="Literal means $VARIABLES in the value is kept as the actual characters '$VARIABLES' instead of being resolved from another variable. Useful when your value contains a $ sign." />
            <x-forms.listbox id="is_buildtime" label="Build time" :options="[
                ['value' => true, 'label' => 'Available during build'],
                ['value' => false, 'label' => 'Not available during build'],
            ]"
                helper="Make this variable available during the Docker build process. Useful for build secrets and dependencies." />
            <x-forms.listbox id="is_runtime" label="Runtime" :options="[
                ['value' => true, 'label' => 'Available in the container'],
                ['value' => false, 'label' => 'Not available in the container'],
            ]" helper="Make this variable available in the running container at runtime." />
        @endif
    </div>

    @if (!$shared)
        <x-environment-variable-warning :problematic-variables="$problematicVariables" />
    @endif

    <div class="flex justify-end border-t border-neutral-200 pt-4 dark:border-white/[0.07]">
        <x-forms.button type="submit" @click="modalOpen = false">
            Add variable
        </x-forms.button>
    </div>
</form>
