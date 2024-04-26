<div x-data="{ error: $wire.entangle('error'), filesize: $wire.entangle('filesize'), filename: $wire.entangle('filename'), isUploading: $wire.entangle('isUploading'), progress: $wire.entangle('progress') }">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/dropzone/5.9.3/dropzone.min.js"
        integrity="sha512-U2WE1ktpMTuRBPoCFDzomoIorbOyUv0sP8B+INA3EzNAhehbzED1rOJg6bCqPf/Tuposxb5ja/MAUnC8THSbLQ=="
        crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    @script
        <script>
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
                    button.innerText = 'Select or Drop a backup file here'
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
        <div class="mt-2 mb-4 rounded alert-error">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 stroke-current shrink-0" fill="none"
                viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <span>This is a destructive action, existing data will be replaced!</span>
        </div>
        @if (str(data_get($resource, 'status'))->startsWith('running'))
            @if ($resource->type() === 'standalone-postgresql')
                <x-forms.input class="mb-2" label="Custom Import Command"
                    wire:model='postgresqlRestoreCommand'></x-forms.input>
            @elseif ($resource->type() === 'standalone-mysql')
                <x-forms.input class="mb-2" label="Custom Import Command"
                    wire:model='mysqlRestoreCommand'></x-forms.input>
            @elseif ($resource->type() === 'standalone-mariadb')
                <x-forms.input class="mb-2" label="Custom Import Command"
                    wire:model='mariadbRestoreCommand'></x-forms.input>
            @endif

            <div x-show="isUploading">
                <progress max="100" x-bind:value="progress" class="progress progress-warning"></progress>
            </div>
            <div x-show="filename && !error">
                <div>File: <span x-text="filename ?? 'N/A'"></span> <span x-text="filesize">/ </span></div>
                <x-forms.button class="w-full my-4" wire:click='runImport'>Restore Backup</x-forms.button>
            </div>

            <form action="/upload/backup/{{ $resource->uuid }}" class="dropzone" id="my-dropzone">
                @csrf
            </form>

            <div class="container w-full mx-auto">
                <livewire:activity-monitor header="Database Restore Output" />
            </div>
        @else
            <div>Database must be running to restore a backup.</div>
        @endif
    @endif
</div>
