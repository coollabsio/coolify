<div x-data="{
    error: $wire.entangle('error'),
    filesize: $wire.entangle('filesize'),
    filename: $wire.entangle('filename'),
    isUploading: $wire.entangle('isUploading'),
    progress: $wire.entangle('progress'),
    s3FileSize: $wire.entangle('s3FileSize'),
    s3StorageId: $wire.entangle('s3StorageId'),
    s3Path: $wire.entangle('s3Path'),
    restoreType: null
}">
    <script type="text/javascript" src="{{ URL::asset('js/dropzone.js') }}"></script>
    @script
    <script data-navigate-once>
        Dropzone.options.myDropzone = {
            chunking: true,
            method: "POST",
            maxFilesize: 1000000000,
            chunkSize: 10000000,
            createImageThumbnails: false,
            disablePreviews: true,
            parallelChunkUploads: false,
            init: function () {
                let button = this.element.querySelector('button');
                button.innerText = 'Select or drop a backup file here.'
                this.on('sending', function (file, xhr, formData) {
                    const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                    formData.append("_token", token);
                });
                this.on("addedfile", file => {
                    $wire.isUploading = true;
                    $wire.customLocation = '';
                });
                this.on('uploadprogress', function (file, progress, bytesSent) {
                    $wire.progress = progress;
                });
                this.on('complete', function (file) {
                    $wire.filename = file.name;
                    $wire.filesize = Number(file.size / 1024 / 1024).toFixed(2) + ' MB';
                    $wire.isUploading = false;
                });
                this.on('error', function (file, message) {
                    $wire.error = true;
                    $wire.$dispatch('error', message.error)
                });
            }
        };
    </script>
    @endscript
    <h2>{{ __('database.import_backup') }}</h2>
    @if ($unsupported)
        <div>{{ __('database.restore_not_supported') }}</div>
    @else
        <div class="pt-2 rounded-sm alert-error">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 stroke-current shrink-0" fill="none" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <span>{{ __('database.destructive_action_warning') }}</span>
        </div>
        @if (str(data_get($resource, 'status'))->startsWith('running'))
            {{-- Restore Command Configuration --}}
            @if ($resource->type() === 'standalone-postgresql')
                @if ($dumpAll)
                    <x-forms.textarea rows="6" readonly label="{{ __('database.custom_import_command') }}"
                        wire:model='restoreCommandText'></x-forms.textarea>
                @else
                    <x-forms.input label="{{ __('database.custom_import_command') }}" wire:model='postgresqlRestoreCommand'></x-forms.input>
                    <div class="flex flex-col gap-1 pt-1">
                        <span class="text-xs">{{ __('database.add_clean_flag') }}</span>
                        <span class="text-xs">{{ __('database.add_verbose_flag') }}</span>
                    </div>
                @endif
                <div class="w-64 pt-2">
                    <x-forms.checkbox label="{{ __('database.backup_includes_all_databases') }}" wire:model.live='dumpAll'></x-forms.checkbox>
                </div>
            @elseif ($resource->type() === 'standalone-mysql')
                @if ($dumpAll)
                    <x-forms.textarea rows="14" readonly label="{{ __('database.custom_import_command') }}"
                        wire:model='restoreCommandText'></x-forms.textarea>
                @else
                    <x-forms.input label="{{ __('database.custom_import_command') }}" wire:model='mysqlRestoreCommand'></x-forms.input>
                @endif
                <div class="w-64 pt-2">
                    <x-forms.checkbox label="{{ __('database.backup_includes_all_databases') }}" wire:model.live='dumpAll'></x-forms.checkbox>
                </div>
            @elseif ($resource->type() === 'standalone-mariadb')
                @if ($dumpAll)
                    <x-forms.textarea rows="14" readonly label="{{ __('database.custom_import_command') }}"
                        wire:model='restoreCommandText'></x-forms.textarea>
                @else
                    <x-forms.input label="{{ __('database.custom_import_command') }}" wire:model='mariadbRestoreCommand'></x-forms.input>
                @endif
                <div class="w-64 pt-2">
                    <x-forms.checkbox label="{{ __('database.backup_includes_all_databases') }}" wire:model.live='dumpAll'></x-forms.checkbox>
                </div>
            @endif

            {{-- Restore Type Selection Boxes --}}
            <h3 class="pt-6">Choose Restore Method</h3>
            <div class="flex gap-4 pt-2">
                <div @click="restoreType = 'file'"
                     class="flex-1 p-6 border-2 rounded-sm cursor-pointer transition-all"
                     :class="restoreType === 'file' ? 'border-warning bg-warning/10' : 'border-neutral-200 dark:border-neutral-800 hover:border-warning/50'">
                    <div class="flex flex-col gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                        </svg>
                        <h4 class="text-lg font-bold">Restore from File</h4>
                        <p class="text-sm text-neutral-600 dark:text-neutral-400">{{ __('database.upload_backup_file') }}</p>
                    </div>
                </div>

                @if ($availableS3Storages->count() > 0)
                    <div @click="restoreType = 's3'"
                         class="flex-1 p-6 border-2 rounded-sm cursor-pointer transition-all"
                         :class="restoreType === 's3' ? 'border-warning bg-warning/10' : 'border-neutral-200 dark:border-neutral-800 hover:border-warning/50'">
                        <div class="flex flex-col gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z" />
                            </svg>
                            <h4 class="text-lg font-bold">Restore from S3</h4>
                            <p class="text-sm text-neutral-600 dark:text-neutral-400">{{ __('database.download_restore_s3') }}</p>
                        </div>
                    </div>
                @endif
            </div>

            {{-- File Restore Section --}}
            @can('update', $resource)
                <div x-show="restoreType === 'file'" class="pt-6">
                    <h3>Backup File</h3>
                    <form class="flex gap-2 items-end pt-2">
                        <x-forms.input label="{{ __('database.location_of_backup_file') }}" placeholder="{{ __('database.location_of_backup_file_placeholder') }}"
                            wire:model='customLocation' x-model="$wire.customLocation"></x-forms.input>
                        <x-forms.button class="w-full" wire:click='checkFile' x-bind:disabled="!$wire.customLocation">{{ __('common.check_file') }}</x-forms.button>
                    </form>
                    <div class="pt-2 text-center text-xl font-bold">
                        {{ __('database.or') }}
                    </div>
                    <form action="/upload/backup/{{ $resource->uuid }}" class="dropzone" id="my-dropzone" wire:ignore>
                        @csrf
                    </form>
                    <div x-show="isUploading">
                        <progress max="100" x-bind:value="progress" class="progress progress-warning"></progress>
                    </div>

                    <div x-show="filename && !error" class="pt-6">
                        <h3>{{ __('database.file_information') }}</h3>
                        <div class="pt-2">{{ __('database.location') }} <span x-text="filename ?? 'N/A'"></span><span x-show="filesize" x-text="' / ' + filesize"></span></div>
                        <div class="pt-2">
                            <x-modal-confirmation title="{{ __('modal.restore_database_from_file') }}" buttonTitle="{{ __('modal.restore_from_file') }}"
                                submitAction="runImport" isErrorButton>
                                <x-slot:button-title>
                                    {{ __('database.restore_database_from_file') }}
                                </x-slot:button-title>
                                {{ __('database.this_will_perform_actions') }}
                                <ul class="list-disc list-inside pt-2">
                                    <li>{{ __('database.copy_backup_to_container') }}</li>
                                    <li>{{ __('database.execute_restore_command') }}</li>
                                </ul>
                                <div class="pt-2 font-bold text-error">{{ __('database.warning_replace_data') }}</div>
                            </x-modal-confirmation>
                        </div>
                    </div>
                </div>
            @endcan

            {{-- S3 Restore Section --}}
            @if ($availableS3Storages->count() > 0)
                @can('update', $resource)
                    <div x-show="restoreType === 's3'" class="pt-6">
                        <h3>{{ __('database.restore_from_s3') }}</h3>
                        <div class="flex flex-col gap-2 pt-2">
                            <x-forms.select label="{{ __('database.s3_storage') }}" wire:model.live="s3StorageId">
                                <option value="">{{ __('database.select_s3_storage_option') }}</option>
                                @foreach ($availableS3Storages as $storage)
                                    <option value="{{ $storage->id }}">{{ $storage->name }}
                                        @if ($storage->description)
                                            - {{ $storage->description }}
                                        @endif
                                    </option>
                                @endforeach
                            </x-forms.select>

                            <x-forms.input label="{{ __('database.s3_file_path') }}"
                                helper="{{ __('database.s3_file_path_helper') }}"
                                placeholder="{{ __('database.s3_file_path_placeholder') }}" wire:model.blur='s3Path'
                                wire:keydown.enter='checkS3File'></x-forms.input>

                            <div class="flex gap-2">
                                <x-forms.button class="w-full" wire:click='checkS3File' x-bind:disabled="!s3StorageId || !s3Path">
                                    {{ __('common.check_file') }}
                                </x-forms.button>
                            </div>

                            @if ($s3FileSize)
                                <div class="pt-6">
                                    <h3>File Information</h3>
                                    <div class="pt-2">Location: {{ $s3Path }} {{ formatBytes($s3FileSize ?? 0) }}</div>
                                    <div class="pt-2">
                                        <x-modal-confirmation title="{{ __('modal.restore_database_from_s3') }}" buttonTitle="{{ __('modal.restore_from_s3') }}"
                                            submitAction="restoreFromS3" isErrorButton>
                                            <x-slot:button-title>
                                                {{ __('database.restore_database_from_s3') }}
                                            </x-slot:button-title>
                                            {{ __('database.this_will_perform_actions') }}
                                            <ul class="list-disc list-inside pt-2">
                                                <li>{{ __('database.download_backup_from_s3') }}</li>
                                                <li>{{ __('database.copy_file_into_container') }}</li>
                                                <li>{{ __('database.execute_restore_command') }}</li>
                                            </ul>
                                            <div class="pt-2 font-bold text-error">{{ __('database.warning_replace_data') }}</div>
                                        </x-modal-confirmation>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                @endcan
            @endif

            {{-- Slide-over for activity monitor (all restore operations) --}}
            <x-slide-over @databaserestore.window="slideOverOpen = true" closeWithX fullScreen>
                <x-slot:title>{{ __('database.database_restore_output') }}</x-slot:title>
                <x-slot:content>
                    <div wire:ignore>
                        <livewire:activity-monitor wire:key="database-restore-{{ $resource->uuid }}" header="Logs" fullHeight />
                    </div>
                </x-slot:content>
            </x-slide-over>
        @else
            <div>{{ __('database.database_must_be_running') }}</div>
        @endif
    @endif
</div>