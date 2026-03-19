<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Environment Variables | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <x-server.sidebar :server="$server" activeMenu="environment-variables" />
        <div class="w-full">
            <div class="flex flex-col gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        <h2>Server Environment Variables</h2>
                    </div>
                    <div>Environment variables defined here will be automatically injected into all applications deployed on this server. Application-level variables take precedence over server-level variables.</div>
                </div>

                @can('update', $server)
                    <form wire:submit='submit' class="flex flex-col gap-4 p-4 bg-white border dark:bg-base dark:border-coolgray-300 border-neutral-200">
                        <h3>Add New Variable</h3>
                        <div class="flex flex-col gap-2 lg:flex-row">
                            <x-forms.input id="key" label="Key" placeholder="VARIABLE_NAME" required />
                            <x-forms.input id="value" label="Value" placeholder="value" type="password" />
                        </div>
                        <div class="flex flex-col gap-2 lg:flex-row lg:items-end">
                            <div class="flex-1">
                                <x-forms.input id="comment" label="Comment" placeholder="Optional description" maxlength="256" />
                            </div>
                            <div class="flex items-center gap-4">
                                <x-forms.checkbox id="is_multiline" label="Is Multiline?" />
                                <x-forms.checkbox id="is_literal" label="Is Literal?"
                                    helper="When enabled, $ characters in the value won't be interpolated as variable references." />
                            </div>
                        </div>
                        <div>
                            <x-forms.button type="submit">Add Variable</x-forms.button>
                        </div>
                    </form>
                @endcan

                <div class="flex flex-col gap-2">
                    @forelse ($this->environmentVariables as $env)
                        <div class="flex flex-col gap-2 p-4 bg-white border dark:bg-base dark:border-coolgray-300 border-neutral-200">
                            <div class="flex items-center justify-between gap-2">
                                <div class="flex items-center gap-2">
                                    <code class="font-bold">{{ $env->key }}</code>
                                    @if ($env->comment)
                                        <span class="text-xs text-neutral-400">{{ $env->comment }}</span>
                                    @endif
                                    @if ($env->is_multiline)
                                        <span class="text-xs px-1 rounded bg-neutral-100 dark:bg-coolgray-400">multiline</span>
                                    @endif
                                    @if ($env->is_literal)
                                        <span class="text-xs px-1 rounded bg-neutral-100 dark:bg-coolgray-400">literal</span>
                                    @endif
                                </div>
                                @can('update', $server)
                                    <x-modal-confirmation title="Confirm Deletion?" isErrorButton buttonTitle="Delete"
                                        submitAction="delete({{ $env->id }})"
                                        :actions="['This environment variable will be permanently deleted from all future deployments on this server.']"
                                        confirmationText="{{ $env->key }}"
                                        confirmationLabel="Enter the variable name to confirm"
                                        shortConfirmationLabel="Variable Name" :confirmWithPassword="false"
                                        step2ButtonText="Permanently Delete" />
                                @endcan
                            </div>
                        </div>
                    @empty
                        <div class="text-neutral-400">No server-level environment variables defined.</div>
                    @endforelse
                </div>

                <div class="p-4 bg-white border dark:bg-base dark:border-coolgray-300 border-neutral-200">
                    <h3>Auto-injected Variables</h3>
                    <div class="text-sm text-neutral-400 mt-2">
                        The following variables are automatically available in all containers on this server:
                    </div>
                    <div class="mt-2 flex flex-col gap-1">
                        <code class="text-sm"><span class="text-neutral-400">COOLIFY_SERVER_ID=</span>{{ $server->uuid }}</code>
                        <code class="text-sm"><span class="text-neutral-400">COOLIFY_SERVER_NAME=</span>{{ $server->name }}</code>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
