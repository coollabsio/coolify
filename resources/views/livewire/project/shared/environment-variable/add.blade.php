<form class="flex flex-col w-full gap-2 rounded-sm" wire:submit='submit'>
    <x-forms.input placeholder="NODE_ENV" id="key" label="{{ __('env_var.name_label') }}" required />
    @if ($is_multiline)
        <x-forms.textarea id="value" label="{{ __('env_var.value_label') }}" required />
    @else
        <x-forms.env-var-input placeholder="production" id="value" label="{{ __('env_var.value_label') }}" required
            :availableVars="$shared ? [] : $this->availableSharedVariables"
            :projectUuid="data_get($parameters, 'project_uuid')"
            :environmentUuid="data_get($parameters, 'environment_uuid')" />
    @endif

    @if (!$shared && !$is_multiline)
        <div class="text-xs text-neutral-500 dark:text-neutral-400 -mt-1">
            {!! __('env_var.shared_var_tip') !!}
        </div>
    @endif

    @if (!$shared)
        <x-forms.checkbox id="is_buildtime"
            helper="{{ __('env_var.buildtime_helper') }}"
            label="{{ __('env_var.buildtime_label') }}" />

        <x-environment-variable-warning :problematic-variables="$problematicVariables" />

        <x-forms.checkbox id="is_runtime" helper="{{ __('env_var.runtime_helper') }}"
            label="{{ __('env_var.runtime_label') }}" />
        <x-forms.checkbox id="is_literal"
            helper="{{ __('env_var.literal_helper') }}"
            label="{{ __('env_var.literal_label') }}" />
    @endif

    <x-forms.checkbox id="is_multiline" label="{{ __('env_var.multiline_label') }}" />
    <x-forms.button type="submit" @click="slideOverOpen=false">
        {{ __('button.save') }}
    </x-forms.button>
</form>