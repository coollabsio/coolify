<div x-data="{ error: $wire.entangle('error'), filesize: $wire.entangle('filesize'), filename: $wire.entangle('filename'), isUploading: $wire.entangle('isUploading'), progress: $wire.entangle('progress') }">
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
                init: function() {
                    let button = this.element.querySelector('button');
                    button.innerText = 'Select or drop a backup file here.'
                    this.on('sending', function(file, xhr, formData) {
                        const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                        formData.append("_token", token);
                    });
                    this.on("addedfile", file => {
                        $wire.isUploading = true;
                    });
                    this.on('uploadprogress', function(file, progress, bytesSent) {
                        $wire.progress = progress;
                    });
                    this.on('complete', function(file) {
                        $wire.filename = file.name;
                        $wire.filesize = Number(file.size / 1024 / 1024).toFixed(2) + ' MB';
                        $wire.isUploading = false;
                    });
                    this.on('error', function(file, message) {
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
        <div class="pt-2 rounded alert-error">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 stroke-current shrink-0" fill="none"
                viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <span>This is a destructive action, existing data will be replaced!</span>
        </div>
        @if (str(data_get($resource, 'status'))->startsWith('running'))
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
                    <x-forms.checkbox label="Backup includes all databases"
                        wire:model.live='dumpAll'></x-forms.checkbox>
                </div>
            @elseif ($resource->type() === 'standalone-mysql')
                @if ($dumpAll)
                    <x-forms.textarea rows="14" readonly label="Custom Import Command"
                        wire:model='restoreCommandText'></x-forms.textarea>
                @else
                    <x-forms.input label="Custom Import Command" wire:model='mysqlRestoreCommand'></x-forms.input>
                @endif
                <div class="w-64 pt-2">
                    <x-forms.checkbox label="Backup includes all databases"
                        wire:model.live='dumpAll'></x-forms.checkbox>
                </div>
            @elseif ($resource->type() === 'standalone-mariadb')
                @if ($dumpAll)
                    <x-forms.textarea rows="14" readonly label="Custom Import Command"
                        wire:model='restoreCommandText'></x-forms.textarea>
                @else
                    <x-forms.input label="Custom Import Command" wire:model='mariadbRestoreCommand'></x-forms.input>
                @endif
                <div class="w-64 pt-2">
                    <x-forms.checkbox label="Backup includes all databases"
                        wire:model.live='dumpAll'></x-forms.checkbox>
                </div>
            @endif
            <h3 class="pt-6">Backup File</h3>
            <form class="flex gap-2 items-end">
                <x-forms.input label="Location of the backup file on the server"
                    placeholder="e.g. /home/user/backup.sql.gz" wire:model='customLocation'></x-forms.input>
                <x-forms.button class="w-full" wire:click='checkFile'>Check File</x-forms.button>
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
            <h3 class="pt-6" x-show="filename && !error">File Information</h3>
            <div x-show="filename && !error">
                <div>Location: <span x-text="filename ?? 'N/A'"></span> <span x-text="filesize">/ </span></div>
                <x-forms.button class="w-full my-4" wire:click='runImport'>Restore Backup</x-forms.button>
            </div>
            <div class="container w-full mx-auto">
                <livewire:activity-monitor header="Database Restore Output" />
            </div>
        @else
            <div>Database must be running to restore a backup.</div>
        @endif
    @endif
</div>
