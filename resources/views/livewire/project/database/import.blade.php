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
    <h2>Import Backup</h2>
    @if ($unsupported)
        <div>Database restore is not supported.</div>
    @else
        <div class="pt-2 rounded-sm alert-error">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 stroke-current shrink-0" fill="none" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <span>This is a destructive action, existing data will be replaced!</span>
        </div>
        @if (str(data_get($resource, 'status'))->startsWith('running'))
            {{-- Restore Command Configuration --}}
            @if ($resource->type() === 'standalone-postgresql')
                @if ($dumpAll)
                    <x-forms.textarea rows="6" readonly label="Custom Import Command"
                        wire:model='restoreCommandText'></x-forms.textarea>
                @else
                    <x-forms.input label="Custom Import Command" wire:model='postgresqlRestoreCommand'></x-forms.input>
                    <div class="flex flex-col gap-1 pt-1">
                        <span class="text-xs">You can add "--clean" to drop objects before creating them, avoiding
                            conflicts.</span>
                        <span class="text-xs">You can add "--verbose" to log more things.</span>
                    </div>
                @endif
                <div class="w-64 pt-2">
                    <x-forms.checkbox label="Backup includes all databases" wire:model.live='dumpAll'></x-forms.checkbox>
                </div>
            @elseif ($resource->type() === 'standalone-mysql')
                @if ($dumpAll)
                    <x-forms.textarea rows="14" readonly label="Custom Import Command"
                        wire:model='restoreCommandText'></x-forms.textarea>
                @else
                    <x-forms.input label="Custom Import Command" wire:model='mysqlRestoreCommand'></x-forms.input>
                @endif
                <div class="w-64 pt-2">
                    <x-forms.checkbox label="Backup includes all databases" wire:model.live='dumpAll'></x-forms.checkbox>
                </div>
            @elseif ($resource->type() === 'standalone-mariadb')
                @if ($dumpAll)
                    <x-forms.textarea rows="14" readonly label="Custom Import Command"
                        wire:model='restoreCommandText'></x-forms.textarea>
                @else
                    <x-forms.input label="Custom Import Command" wire:model='mariadbRestoreCommand'></x-forms.input>
                @endif
                <div class="w-64 pt-2">
                    <x-forms.checkbox label="Backup includes all databases" wire:model.live='dumpAll'></x-forms.checkbox>
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
                        <p class="text-sm text-neutral-600 dark:text-neutral-400">Upload a backup file or specify a file path on the server</p>
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
                            <p class="text-sm text-neutral-600 dark:text-neutral-400">Download and restore a backup from S3 storage</p>
                        </div>
                    </div>
                @endif
            </div>

            {{-- File Restore Section --}}
            @can('update', $resource)
                <div x-show="restoreType === 'file'" class="pt-6">
                    <h3>Backup File</h3>
                    <form class="flex gap-2 items-end pt-2">
                        <x-forms.input label="Location of the backup file on the server" placeholder="e.g. /home/user/backup.sql.gz"
                            wire:model='customLocation' x-model="$wire.customLocation"></x-forms.input>
                        <x-forms.button class="w-full" wire:click='checkFile' x-bind:disabled="!$wire.customLocation">Check File</x-forms.button>
                    </form>
                    <div class="pt-2 text-center text-xl font-bold">
                        Or
                    </div>
                    <form action="/upload/backup/{{ $resource->uuid }}" class="dropzone" id="my-dropzone" wire:ignore>
                        @csrf
                    </form>
                    <div x-show="isUploading">
                        <progress max="100" x-bind:value="progress" class="progress progress-warning"></progress>
                    </div>

                    <div x-show="filename && !error" class="pt-6">
                        <h3>File Information</h3>
                        <div class="pt-2">Location: <span x-text="filename ?? 'N/A'"></span><span x-show="filesize" x-text="' / ' + filesize"></span></div>
                        <div class="pt-2">
                            <x-modal-confirmation title="Restore Database from File?" buttonTitle="Restore from File"
                                submitAction="runImport" isErrorButton>
                                <x-slot:button-title>
                                    Restore Database from File
                                </x-slot:button-title>
                                This will perform the following actions:
                                <ul class="list-disc list-inside pt-2">
                                    <li>Copy backup file to database container</li>
                                    <li>Execute restore command</li>
                                </ul>
                                <div class="pt-2 font-bold text-error">WARNING: This will REPLACE all existing data!</div>
                            </x-modal-confirmation>
                        </div>
                    </div>
                </div>
            @endcan

            {{-- S3 Restore Section --}}
            @if ($availableS3Storages->count() > 0)
                @can('update', $resource)
                    <div x-show="restoreType === 's3'" class="pt-6">
                        <h3>Restore from S3</h3>
                        <div class="flex flex-col gap-2 pt-2">
                            <x-forms.select label="S3 Storage" wire:model.live="s3StorageId">
                                <option value="">Select S3 Storage</option>
                                @foreach ($availableS3Storages as $storage)
                                    <option value="{{ $storage->id }}">{{ $storage->name }}
                                        @if ($storage->description)
                                            - {{ $storage->description }}
                                        @endif
                                    </option>
                                @endforeach
                            </x-forms.select>

                            <x-forms.input label="S3 File Path (within bucket)"
                                helper="Path to the backup file in your S3 bucket, e.g., /backups/database-2025-01-15.gz"
                                placeholder="/backups/database-backup.gz" wire:model.blur='s3Path'
                                wire:keydown.enter='checkS3File'></x-forms.input>

                            <div class="flex gap-2">
                                <x-forms.button class="w-full" wire:click='checkS3File' x-bind:disabled="!s3StorageId || !s3Path">
                                    Check File
                                </x-forms.button>
                            </div>

                            @if ($s3FileSize)
                                <div class="pt-6">
                                    <h3>File Information</h3>
                                    <div class="pt-2">Location: {{ $s3Path }} {{ formatBytes($s3FileSize ?? 0) }}</div>
                                    <div class="pt-2">
                                        <x-modal-confirmation title="Restore Database from S3?" buttonTitle="Restore from S3"
                                            submitAction="restoreFromS3" isErrorButton>
                                            <x-slot:button-title>
                                                Restore Database from S3
                                            </x-slot:button-title>
                                            This will perform the following actions:
                                            <ul class="list-disc list-inside pt-2">
                                                <li>Download backup from S3 storage</li>
                                                <li>Copy file into database container</li>
                                                <li>Execute restore command</li>
                                            </ul>
                                            <div class="pt-2 font-bold text-error">WARNING: This will REPLACE all existing data!</div>
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
                <x-slot:title>Database Restore Output</x-slot:title>
                <x-slot:content>
                    <livewire:activity-monitor wire:key="database-restore-{{ $resource->uuid }}" header="Logs" fullHeight />
                </x-slot:content>
            </x-slide-over>
        @else
            <div>Database must be running to restore a backup.</div>
        @endif
    @endif
</div>