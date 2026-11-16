@props([
    'action',
    'applicationUuid' => null,
    'message' => 'Select or drop a .tar, .tar.gz, or .tgz archive here.',
])
{{-- Uploads the archive in chunks to the team staging area, then calls the Livewire $action with the upload ID. --}}
<div x-data="{
    uploading: false,
    progress: 0,
    mountDropzone(element) {
        const wire = this.$wire;
        const state = this;
        const attach = () => {
            window.Dropzone.autoDiscover = false;
            if (element.dropzone) {
                return;
            }
            new window.Dropzone(element, {
                chunking: true,
                forceChunking: true,
                method: 'POST',
                maxFilesize: 1024,
                chunkSize: 10000000,
                acceptedFiles: '.tar,.gz,.tgz',
                createImageThumbnails: false,
                disablePreviews: true,
                parallelChunkUploads: false,
                dictDefaultMessage: @js($message),
                init() {
                    this.on('sending', (file, xhr, formData) => {
                        formData.append('_token', document.querySelector('meta[name=csrf-token]').getAttribute('content'));
                        if (@js($applicationUuid)) {
                            formData.append('application_uuid', @js($applicationUuid));
                        }
                    });
                    this.on('addedfile', () => {
                        state.uploading = true;
                        state.progress = 0;
                    });
                    this.on('uploadprogress', (file, progress) => {
                        state.progress = progress;
                    });
                    this.on('success', (file) => {
                        wire.call(@js($action), file.upload.uuid);
                    });
                    this.on('error', (file, message) => {
                        wire.$dispatch('error', message?.message ?? message?.error ?? message);
                    });
                    this.on('complete', (file) => {
                        state.uploading = false;
                        this.removeFile(file);
                    });
                },
            });
        };
        if (window.Dropzone) {
            attach();
            return;
        }
        let script = document.querySelector('script[data-dropzone]');
        if (! script) {
            script = document.createElement('script');
            script.src = @js(URL::asset('js/dropzone.js'));
            script.dataset.dropzone = '';
            document.head.appendChild(script);
        }
        script.addEventListener('load', attach, { once: true });
    },
}" {{ $attributes }}>
    <form action="{{ route('upload.static-archive') }}"
        class="dropzone rounded-lg! border! border-dashed! border-neutral-300! bg-white! dark:border-white/[0.12]! dark:bg-white/[0.05]!"
        x-init="mountDropzone($el)" wire:ignore>
        @csrf
    </form>
    <div x-cloak x-show="uploading" class="mt-3 h-1.5 overflow-hidden rounded-full bg-neutral-200 dark:bg-white/[0.08]">
        <div class="h-full rounded-full bg-coollabs dark:bg-brand" :style="`width: ${progress}%`"></div>
    </div>
</div>
