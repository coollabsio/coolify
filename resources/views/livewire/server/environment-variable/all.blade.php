<div>
    <x-slot:title>
        Server Environment Variables | Coolify
    </x-slot:title>
    <x-server.navbar :server="$server" />
    <div class="flex flex-col gap-4">
        <div>
            <div class="flex items-center gap-2">
                <h2>Server Environment Variables</h2>
                @can('update', $server)
                    <x-forms.button
                        wire:click='switch'>{{ $view === 'normal' ? 'Developer view' : 'Normal view' }}</x-forms.button>
                @endcan
            </div>
            <div>Environment variables defined here will be available to all applications deployed on this server. Application-level variables take precedence over server-level variables.</div>
        </div>

        @if ($view === 'normal')
            @can('update', $server)
                <form wire:submit='addVariable' class="flex flex-col gap-2 p-4 bg-white border dark:bg-base dark:border-coolgray-300 border-neutral-200">
                    <h3>Add New Variable</h3>
                    <div class="flex flex-col w-full gap-2 lg:flex-row">
                        <x-forms.input required id="newKey" label="Key" placeholder="VARIABLE_NAME" />
                        <x-forms.input required id="newValue" label="Value" type="password" placeholder="value" />
                    </div>
                    <div class="flex items-center gap-4">
                        <x-forms.checkbox id="newIsBuildtime" label="Available at Buildtime"
                            helper="Make this variable available during Docker build process." />
                        <x-forms.checkbox id="newIsMultiline" label="Is Multiline?" />
                        <x-forms.checkbox id="newIsLiteral" label="Is Literal?"
                            helper="Prevents variable interpolation. Use when value contains $ characters." />
                    </div>
                    <x-forms.button type="submit">Add</x-forms.button>
                </form>
            @endcan

            @forelse ($this->environmentVariables as $env)
                <div class="flex flex-col items-center gap-4 p-4 bg-white border lg:items-start dark:bg-base dark:border-coolgray-300 border-neutral-200">
                    <div class="flex flex-col w-full gap-2 lg:flex-row">
                        <x-forms.input value="{{ $env->key }}" disabled label="Key" />
                        <x-forms.input value="{{ $env->is_shown_once ? '(Locked)' : $env->value }}" disabled type="password" label="Value" />
                    </div>
                    <div class="flex w-full items-center justify-between">
                        <div class="flex items-center gap-4">
                            <span class="text-xs {{ $env->is_buildtime ? 'dark:text-warning' : 'text-neutral-400' }}">
                                {{ $env->is_buildtime ? 'Available at buildtime' : 'Runtime only' }}
                            </span>
                            @if ($env->is_literal)
                                <span class="text-xs text-neutral-400">Literal</span>
                            @endif
                            @if ($env->is_multiline)
                                <span class="text-xs text-neutral-400">Multiline</span>
                            @endif
                        </div>
                        @can('update', $server)
                            <x-modal-confirmation title="Confirm Environment Variable Deletion?" isErrorButton
                                buttonTitle="Delete" submitAction="deleteEnvironmentVariable('{{ $env->uuid }}')"
                                :actions="['The selected environment variable will be permanently deleted.']"
                                confirmationText="{{ $env->key }}"
                                confirmationLabel="Please confirm by entering the variable name"
                                shortConfirmationLabel="Variable Name" :confirmWithPassword="false"
                                step2ButtonText="Permanently Delete" />
                        @endcan
                    </div>
                </div>
            @empty
                <div>No environment variables found.</div>
            @endforelse
        @else
            <form wire:submit.prevent='submit' class="flex flex-col gap-2">
                <x-forms.textarea rows="10" id="variables" label="Environment Variables (KEY=VALUE format)" />
                @can('update', $server)
                    <x-forms.button type="submit">Save</x-forms.button>
                @endcan
            </form>
        @endif
    </div>
</div>
