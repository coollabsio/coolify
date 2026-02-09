<form class="flex flex-col w-full gap-2 rounded-sm" wire:submit='submit'>
    <x-forms.input placeholder="NODE_ENV" id="key" label="Name" required />
    @if ($is_multiline)
        <x-forms.textarea id="value" label="Value" required />
    @else
        <x-forms.env-var-input placeholder="production" id="value" label="Value" required
            :availableVars="$shared ? [] : $this->availableSharedVariables"
            :projectUuid="data_get($parameters, 'project_uuid')"
            :environmentUuid="data_get($parameters, 'environment_uuid')" />
    @endif

    @if (!$shared && !$is_multiline)
        <div class="text-xs text-neutral-500 dark:text-neutral-400 -mt-1">
            Tip: Type <span class="font-mono dark:text-warning text-coollabs">{{</span> to reference a shared environment
            variable
        </div>
    @endif

    @if (!$shared)
        <x-forms.checkbox id="is_buildtime"
            helper="Make this variable available during Docker build process. Useful for build secrets and dependencies."
            label="Available at Buildtime" />

        <x-environment-variable-warning :problematic-variables="$problematicVariables" />

        <x-forms.checkbox id="is_runtime" helper="Make this variable available in the running container at runtime."
            label="Available at Runtime" />
        <x-forms.checkbox id="is_literal"
            helper="This means that when you use $VARIABLES in a value, it should be interpreted as the actual characters '$VARIABLES' and not as the value of a variable named VARIABLE.<br><br>Useful if you have $ sign in your value and there are some characters after it, but you would not like to interpolate it from another value. In this case, you should set this to true."
            label="Is Literal?" />

        <div class="pt-2 border-t border-neutral-200 dark:border-neutral-700">
            <h4 class="text-sm font-semibold mb-2">Docker Compose Service Scoping (Issue #7655)</h4>

            <x-forms.checkbox id="is_interpolation_only"
                helper="If checked, this variable is ONLY for ${VAR} substitution in docker-compose.yml and will NOT be available in containers at runtime. This respects Docker Compose semantics where .env is for interpolation only."
                label="Interpolation Only (for ${VAR} in compose file)" />

            @if (!$is_interpolation_only)
                <x-forms.select id="injection_method" label="Injection Method"
                    helper="How to inject this variable into containers: 'environment' adds it directly to environment: section, 'env_file' creates per-service .env files, 'none' is for interpolation only.">
                    <option value="environment">environment: (Direct injection)</option>
                    <option value="env_file">env_file: (File-based)</option>
                    <option value="none">None (Interpolation only)</option>
                </x-forms.select>

                <div class="text-xs text-neutral-500 dark:text-neutral-400 mb-2">
                    Service Names (comma-separated, or 'all' for all services):
                </div>
                <x-forms.input id="service_names" placeholder="web,api,worker or 'all'"
                    helper="Specify which Docker Compose services should receive this variable. Use 'all' for all services, or comma-separated service names (e.g., 'web,api'). This prevents secret leakage across containers."
                    wire:model="service_names_input" />
            @endif
        </div>
    @endif

    <x-forms.checkbox id="is_multiline" label="Is Multiline?" />
    <x-forms.button type="submit" @click="slideOverOpen=false">
        Save
    </x-forms.button>
</form>