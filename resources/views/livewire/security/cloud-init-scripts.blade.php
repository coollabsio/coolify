<div>
    <x-security.navbar />
    <div class="flex gap-2">
        <h2 class="pb-4">{{ __('security.cloud_init_scripts') }}</h2>
        @can('create', App\Models\CloudInitScript::class)
            <x-modal-input buttonTitle="{{ __('button.add') }}" title="{{ __('modal.new_cloud_init_script') }}">
                <livewire:security.cloud-init-script-form />
            </x-modal-input>
        @endcan
    </div>
    <div class="pb-4 text-sm">{!! __('security.manage_cloud_init_helper') !!}</div>

    <div class="grid gap-4 lg:grid-cols-2">
        @forelse ($scripts as $script)
            <div wire:key="script-{{ $script->id }}"
                class="flex flex-col gap-1 p-2 border dark:border-coolgray-200 hover:no-underline">
                <div class="flex justify-between items-center">
                    <div class="flex-1">
                        <div class="font-bold dark:text-white">{{ $script->name }}</div>
                        <div class="text-xs text-neutral-500 dark:text-neutral-400">
                            {{ __('security.created_at') }} {{ $script->created_at->diffForHumans() }}
                        </div>
                    </div>
                </div>

                <div class="flex gap-2 mt-2">
                    @can('update', $script)
                        <x-modal-input buttonTitle="{{ __('security.edit') }}" title="{{ __('modal.edit_cloud_init_script') }}" fullWidth>
                            <livewire:security.cloud-init-script-form :scriptId="$script->id"
                                wire:key="edit-{{ $script->id }}" />
                        </x-modal-input>
                    @endcan

                    @can('delete', $script)
                        <x-modal-confirmation title="{{ __('modal.confirm_script_deletion') }}" isErrorButton buttonTitle="{{ __('modal.delete_script') }}"
                            submitAction="deleteScript({{ $script->id }})" :actions="[
                                __('security.delete_script_warning_1'),
                                __('security.delete_script_warning_2'),
                            ]" confirmationText="{{ $script->name }}"
                            confirmationLabel="{{ __('security.confirm_delete_script_label') }}"
                            shortConfirmationLabel="{{ __('security.script_name') }}" :confirmWithPassword="false"
                            step2ButtonText="{{ __('security.delete_script') }}" />
                    @endcan
                </div>
            </div>
        @empty
            <div class="text-neutral-500">{{ __('security.no_cloud_init_scripts') }}</div>
        @endforelse
    </div>
</div>
