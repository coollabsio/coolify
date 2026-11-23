@props([
    'action',
    'prepareS3Action' => null,
    'storages' => collect(),
    'applicationUuid' => null,
    'message' => 'Select or drop a .tar, .tar.gz, or .tgz archive here.',
])
{{-- Uploads the archive in chunks to the team staging area, or with one presigned PUT straight to an S3 storage of the
     team ($prepareS3Action returns the URL), then calls the Livewire $action with the upload ID. --}}
<div x-data="{
    uploading: false,
    progress: 0,
    storage: '',
    async uploadToS3(file, dropzone) {
        const wire = this.$wire;
        try {
            const upload = await wire.call(@js($prepareS3Action), this.storage);
            if (! upload) {
                return;
            }
            await new Promise((resolve, reject) => {
                const xhr = new XMLHttpRequest();
                xhr.open('PUT', upload.url);
                Object.entries(upload.headers).forEach(([name, value]) => xhr.setRequestHeader(name, value));
                xhr.upload.onprogress = (event) => {
                    if (event.lengthComputable) {
                        this.progress = event.loaded / event.total * 100;
                    }
                };
                xhr.onload = () => xhr.status >= 200 && xhr.status < 300
                    ? resolve()
                    : reject(new Error(`The S3 storage refused the upload (HTTP ${xhr.status}).`));
                xhr.onerror = () => reject(new Error(`The upload to the S3 storage failed. The bucket must have a CORS rule that allows PUT from ${window.location.origin}.`));
                xhr.send(file);
            });
            await wire.call(@js($action), upload.upload_id);
        } catch (error) {
            wire.$dispatch('error', error.message);
        } finally {
            this.uploading = false;
            dropzone.removeFile(file);
        }
    },
    mountDropzone(element) {
        const wire = this.$wire;
        const state = this;
        const attach = () => {
            window.Dropzone.autoDiscover = false;
            if (element.dropzone) {
                return;
            }
            new window.Dropzone(element, {
                url: @js(route('upload.static-archive')),
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
                accept(file, done) {
                    if (! state.storage) {
                        done();
                        return;
                    }
                    state.uploadToS3(file, this);
                },
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
    @if ($prepareS3Action && $storages->isNotEmpty())
        <div class="mb-3 flex w-full flex-col gap-1 sm:w-96">
            <x-forms.listbox htmlId="static-archive-upload-target" label="Upload to" :wire="false" x-model="storage"
                :value="''" :options="[
                    ['value' => '', 'label' => 'This Coolify instance'],
                    ...$storages->map(fn ($storage) => ['value' => $storage->uuid, 'label' => 'S3 storage: '.$storage->name])->values()->all(),
                ]" />
            <p x-cloak x-show="storage" class="text-xs text-neutral-500 dark:text-fg-dim">
                The browser sends the archive straight to the bucket, so the bucket needs a CORS rule that allows
                <span class="font-mono">PUT</span> from <span class="font-mono" x-text="window.location.origin"></span>.
                Coolify reads the archive from the bucket to check it, and deletes it after the deployment.
            </p>
        </div>
    @endif
    {{-- A div, not a form: this component can sit inside another form, where a nested form is dropped by the browser. --}}
    <div class="dropzone rounded-lg! border! border-dashed! border-neutral-300! bg-white! dark:border-white/[0.12]! dark:bg-white/[0.05]!"
        x-init="mountDropzone($el)" wire:ignore></div>
    <div x-cloak x-show="uploading" class="mt-3 h-1.5 overflow-hidden rounded-full bg-neutral-200 dark:bg-white/[0.08]">
        <div class="h-full rounded-full bg-coollabs dark:bg-brand" :style="`width: ${progress}%`"></div>
    </div>
</div>
