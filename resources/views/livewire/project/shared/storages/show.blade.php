<div>
    <form wire:submit='submit' class="flex flex-col items-center gap-4 p-4 bg-white border lg:items-start dark:bg-base dark:border-coolgray-300 border-neutral-200">
        @if ($isReadOnly)
            @if (!$storage->isServiceResource() && !$storage->isDockerComposeResource())
                <div class="w-full p-2 text-sm rounded bg-warning/10 text-warning">
                    {{ __('storage.readonly_ui_warning') }}
                </div>
            @endif
            @if ($isFirst)
                <div class="flex gap-2 items-end w-full  md:flex-row flex-col">
                    @if (
                        $storage->resource_type === 'App\Models\ServiceApplication' ||
                            $storage->resource_type === 'App\Models\ServiceDatabase')
                        <x-forms.input id="name" label="{{ __('storage.volume_name') }}" required readonly
                            helper="{{ __('storage.volume_name_change_warning') }}" />
                    @else
                        <x-forms.input id="name" label="{{ __('storage.volume_name') }}" required readonly
                            helper="{{ __('storage.volume_name_change_warning') }}" />
                    @endif
                    @if ($isService || $startedAt)
                        <x-forms.input id="hostPath" readonly helper="{{ __('storage.source_path_desc') }}"
                            label="{{ __('storage.source_path') }}"
                            helper="{{ __('storage.source_path_change_warning') }}" />
                        <x-forms.input id="mountPath" label="{{ __('storage.destination_path') }}"
                            helper="{{ __('storage.destination_path_desc') }}" required readonly />
                    @else
                        <x-forms.input id="hostPath" readonly helper="{{ __('storage.source_path_desc') }}"
                            label="{{ __('storage.source_path') }}"
                            helper="{{ __('storage.source_path_change_warning') }}" />
                        <x-forms.input id="mountPath" label="{{ __('storage.destination_path') }}"
                            helper="{{ __('storage.destination_path_desc') }}" required readonly />
                    @endif
                </div>
            @else
                <div class="flex gap-2 items-end w-full">
                    <x-forms.input id="name" required readonly />
                    <x-forms.input id="hostPath" readonly />
                    <x-forms.input id="mountPath" required readonly />
                </div>
            @endif
        @else
            @can('update', $resource)
                @if ($isFirst)
                    <div class="flex gap-2 items-end w-full">
                        <x-forms.input id="name" label="{{ __('storage.volume_name') }}" required />
                        <x-forms.input id="hostPath" helper="{{ __('storage.source_path_desc') }}" label="{{ __('storage.source_path') }}" />
                        <x-forms.input id="mountPath" label="{{ __('storage.destination_path') }}"
                            helper="{{ __('storage.destination_path_desc') }}" required />
                    </div>
                @else
                    <div class="flex gap-2 items-end w-full">
                        <x-forms.input id="name" required />
                        <x-forms.input id="hostPath" />
                        <x-forms.input id="mountPath" required />
                    </div>
                @endif
                <div class="flex gap-2">
                    <x-forms.button type="submit">
                        {{ __('button.save') }}
                    </x-forms.button>
                    <x-modal-confirmation title="{{ __('storage.confirm_delete_title') }}" isErrorButton buttonTitle="{{ __('button.delete') }}"
                        submitAction="delete" :actions="[
                            __('storage.delete_action_1'),
                            __('storage.delete_action_2'),
                        ]" confirmationText="{{ $storage->name }}"
                        confirmationLabel="{{ __('storage.confirm_delete_label') }}"
                        shortConfirmationLabel="{{ __('storage.storage_name') }}" />
                </div>
            @else
                @if ($isFirst)
                    <div class="flex gap-2 items-end w-full">
                        <x-forms.input id="name" label="{{ __('storage.volume_name') }}" required disabled />
                        <x-forms.input id="hostPath" helper="{{ __('storage.source_path_desc') }}" label="{{ __('storage.source_path') }}"
                            disabled />
                        <x-forms.input id="mountPath" label="{{ __('storage.destination_path') }}"
                            helper="{{ __('storage.destination_path_desc') }}" required disabled />
                    </div>
                @else
                    <div class="flex gap-2 items-end w-full">
                        <x-forms.input id="name" required disabled />
                        <x-forms.input id="hostPath" disabled />
                        <x-forms.input id="mountPath" required disabled />
                    </div>
                @endif
            @endcan
        @endif
    </form>
</div>
