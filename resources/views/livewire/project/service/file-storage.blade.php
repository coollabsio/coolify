<div>
    <div class="flex flex-col gap-4 p-4 bg-white border dark:bg-base dark:border-coolgray-300 border-neutral-200">
        @if ($isReadOnly)
            <div class="w-full p-2 text-sm rounded bg-warning/10 text-warning">
                @if ($fileStorage->is_directory)
                    {{ __('storage.readonly_directory') }}
                @else
                    {{ __('storage.readonly_file') }}
                @endif
            </div>
        @endif
        <div class="flex flex-col justify-center text-sm select-text">
            <div class="flex gap-2  md:flex-row flex-col">
                <x-forms.input label="{{ __('storage.source_path') }}" :value="$fileStorage->fs_path" readonly />
                <x-forms.input label="{{ __('storage.destination_path') }}" :value="$fileStorage->mount_path" readonly />
            </div>
        </div>
        <form wire:submit='submit' class="flex flex-col gap-2">
            @if (!$isReadOnly)
                @can('update', $resource)
                    <div class="flex gap-2">
                        @if ($fileStorage->is_directory)
                            <x-modal-confirmation :ignoreWire="false" title="{{ __('modal.confirm_directory_conversion_to_file') }}"
                                buttonTitle="{{ __('storage.convert_to_file') }}" submitAction="convertToFile" :actions="[
                                    __('storage.confirm_conversion_to_file_warning'),
                                ]"
                                confirmationText="{{ $fs_path }}"
                                confirmationLabel="{{ __('storage.confirm_filepath_label') }}"
                                shortConfirmationLabel="{{ __('storage.filepath') }}" :confirmWithPassword="false" step2ButtonText="{{ __('storage.convert_to_file') }}" />
                            <x-modal-confirmation :ignoreWire="false" title="{{ __('modal.confirm_directory_deletion') }}" buttonTitle="{{ __('modal.delete_directory') }}"
                                isErrorButton submitAction="delete" :checkboxes="$directoryDeletionCheckboxes" :actions="[
                                    __('storage.confirm_directory_deletion_warning'),
                                ]"
                                confirmationText="{{ $fs_path }}"
                                confirmationLabel="{{ __('storage.confirm_filepath_label') }}"
                                shortConfirmationLabel="{{ __('storage.filepath') }}" />
                        @else
                            @if (!$fileStorage->is_binary)
                                <x-modal-confirmation :ignoreWire="false" title="{{ __('modal.confirm_file_conversion_to_directory') }}"
                                    buttonTitle="{{ __('storage.convert_to_directory') }}" submitAction="convertToDirectory" :actions="[
                                        __('storage.confirm_conversion_to_directory_warning'),
                                    ]"
                                    confirmationText="{{ $fs_path }}"
                                    confirmationLabel="{{ __('storage.confirm_filepath_label') }}"
                                    shortConfirmationLabel="{{ __('storage.filepath') }}" :confirmWithPassword="false"
                                    step2ButtonText="{{ __('storage.convert_to_directory') }}" />
                            @endif
                            <x-forms.button type="button" wire:click="loadStorageOnServer">{{ __('common.load_from_server') }}</x-forms.button>
                            <x-modal-confirmation :ignoreWire="false" title="{{ __('modal.confirm_file_deletion') }}" buttonTitle="{{ __('modal.delete_file') }}"
                                isErrorButton submitAction="delete" :checkboxes="$fileDeletionCheckboxes" :actions="[__('storage.confirm_file_deletion_warning')]"
                                confirmationText="{{ $fs_path }}"
                                confirmationLabel="{{ __('storage.confirm_filepath_label') }}"
                                shortConfirmationLabel="{{ __('storage.filepath') }}" />
                        @endif
                    </div>
                @endcan
                @if (!$fileStorage->is_directory)
                    @can('update', $resource)
                        @if (data_get($resource, 'settings.is_preserve_repository_enabled'))
                            <div class="w-96">
                                <x-forms.checkbox instantSave label="{{ __('storage.is_based_on_git') }}"
                                    id="isBasedOnGit"></x-forms.checkbox>
                            </div>
                        @endif
                        <x-forms.textarea
                            label="{{ $fileStorage->is_based_on_git ? __('storage.content_refreshed') : __('storage.content') }}"
                            helper="{{ __('storage.content_outdated_helper') }}"
                            rows="20" id="content"
                            readonly="{{ $fileStorage->is_based_on_git || $fileStorage->is_binary }}"></x-forms.textarea>
                        @if (!$fileStorage->is_based_on_git && !$fileStorage->is_binary)
                            <x-forms.button class="w-full" type="submit">{{ __('common.save') }}</x-forms.button>
                        @endif
                    @else
                        @if (data_get($resource, 'settings.is_preserve_repository_enabled'))
                            <div class="w-96">
                                <x-forms.checkbox disabled label="{{ __('storage.is_based_on_git') }}"
                                                                id="isBasedOnGit"></x-forms.checkbox>                            </div>
                        @endif
                        <x-forms.textarea
                            label="{{ $fileStorage->is_based_on_git ? __('storage.content_refreshed') : __('storage.content') }}"
                            helper="{{ __('storage.content_outdated_helper') }}"
                            rows="20" id="content" disabled></x-forms.textarea>
                    @endcan
                @endif
            @else
                {{-- Read-only view --}}
                @if (!$fileStorage->is_directory)
                    @can('view', $resource)
                        <div class="flex gap-2">
                            <x-forms.button type="button" wire:click="loadStorageOnServer">{{ __('common.load_from_server') }}</x-forms.button>
                        </div>
                    @endcan
                    @if (data_get($resource, 'settings.is_preserve_repository_enabled'))
                        <div class="w-96">
                            <x-forms.checkbox disabled label="{{ __('storage.is_based_on_git') }}"
                                id="isBasedOnGit"></x-forms.checkbox>
                        </div>
                    @endif
                    <x-forms.textarea
                        label="{{ $fileStorage->is_based_on_git ? __('storage.content_refreshed') : __('storage.content') }}"
                        helper="{{ __('storage.content_outdated_helper') }}"
                        rows="20" id="content" disabled></x-forms.textarea>
                @endif
            @endif
        </form>
    </div>
</div>
