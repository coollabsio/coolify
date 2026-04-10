<div
    x-data="{
        error: $wire.entangle('error'),
        filesize: $wire.entangle('filesize'),
        filename: $wire.entangle('filename'),
        hasPendingUpload: $wire.entangle('hasPendingUpload'),
        isUploading: $wire.entangle('isUploading'),
        progress: $wire.entangle('progress'),
        filePath: $wire.entangle('filePath'),
        selectedUuid: $wire.entangle('selectedUuid'),
        isDragging: false,
        chunkSize: 5 * 1024 * 1024,
        maxFileSize: 10 * 1024 * 1024 * 1024,
        uploadUrl: @js(route('upload.terminal')),
        csrfToken: @js(csrf_token()),
        async handleFileSelection(event) {
            const [file] = event.target.files;
            event.target.value = '';

            if (file) {
                await this.uploadFile(file);
            }
        },
        async handleDrop(event) {
            this.isDragging = false;
            const [file] = event.dataTransfer.files;

            if (file) {
                await this.uploadFile(file);
            }
        },
        async uploadFile(file) {
            await $wire.resetPendingUpload();

            this.error = false;
            this.filePath = null;
            this.filename = file.name;
            this.filesize = this.formatFileSize(file.size);
            this.hasPendingUpload = false;
            this.isUploading = true;
            this.progress = 0;

            if (file.size > this.maxFileSize) {
                this.error = true;
                this.isUploading = false;
                await $wire.handleUploadError('File is too large. Maximum size is 10 GB.');

                return;
            }

            const uploadToken = this.generateUploadToken();
            const totalChunks = Math.max(1, Math.ceil(file.size / this.chunkSize));

            try {
                for (let index = 0; index < totalChunks; index++) {
                    const start = index * this.chunkSize;
                    const end = Math.min(file.size, start + this.chunkSize);
                    const chunk = file.slice(start, end);
                    const formData = new FormData();

                    formData.append('file', chunk, file.name);
                    formData.append('resumableChunkNumber', String(index + 1));
                    formData.append('resumableTotalChunks', String(totalChunks));
                    formData.append('resumableChunkSize', String(this.chunkSize));
                    formData.append('resumableCurrentChunkSize', String(chunk.size));
                    formData.append('resumableTotalSize', String(file.size));
                    formData.append('resumableType', file.type || 'application/octet-stream');
                    formData.append('resumableIdentifier', uploadToken);
                    formData.append('resumableFilename', file.name);
                    formData.append('resumableRelativePath', file.name);

                    const response = await fetch(this.uploadUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': this.csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                        body: formData,
                    });

                    let data = {};

                    try {
                        data = await response.json();
                    } catch {
                        data = {};
                    }

                    if (! response.ok || data.error) {
                        throw new Error(data.error || 'File upload failed.');
                    }

                    this.progress = typeof data.done === 'number'
                        ? Math.round(data.done)
                        : Math.round(((index + 1) / totalChunks) * 100);

                    if (data.file_uuid) {
                        await $wire.registerUploadedFile(
                            data.file_uuid,
                            data.original_name || file.name,
                            Number(data.size || file.size),
                        );
                    }
                }
            } catch (error) {
                this.error = true;
                this.isUploading = false;
                this.progress = 0;
                this.hasPendingUpload = false;
                await $wire.resetPendingUpload();
                await $wire.handleUploadError(error.message || 'File upload failed.');

                return;
            }

            this.isUploading = false;
        },
        formatFileSize(bytes) {
            const units = ['B', 'KB', 'MB', 'GB', 'TB'];
            let size = bytes;
            let unitIndex = 0;

            while (size >= 1024 && unitIndex < units.length - 1) {
                size /= 1024;
                unitIndex++;
            }

            const precision = size >= 10 || unitIndex === 0 ? 0 : 2;

            return `${Number(size.toFixed(precision))} ${units[unitIndex]}`;
        },
        generateUploadToken() {
            const token = window.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`;

            return token.replace(/[^a-zA-Z0-9_-]/g, '');
        },
    }"
    @path-copied.window="navigator.clipboard.writeText($event.detail.path)">

    <x-callout type="warning" title="Security Notice">
        Uploaded files are stored temporarily and will be automatically deleted after the expiration time.
        Do not upload sensitive files without encryption. Always verify file permissions after upload.
    </x-callout>

    <div class="flex gap-2 my-3">
        <div class="w-full">
            <x-forms.select id="terminal-upload-target" label="Target" wire:model.live="selectedUuid" helper="Choose the server or running container that should receive the uploaded file.">
                <option value="default">Select a server or container</option>
                @foreach ($servers as $server)
                    <option value="{{ $server['uuid'] }}">{{ $server['name'] }}</option>
                    @foreach ($containers as $container)
                        @if ($container['server_uuid'] === $server['uuid'])
                            <option value="{{ $container['uuid'] }}">
                                {{ $server['name'] }} -> {{ $container['name'] }}
                            </option>
                        @endif
                    @endforeach
                @endforeach
            </x-forms.select>
        </div>
        <div class="min-w-64">
            <x-forms.select id="expirationMinutes" label="File Expiration" wire:model="expirationMinutes" helper="File will be automatically deleted after this time for security.">
                @foreach ($this->expirationOptions as $minutes => $label)
                    <option value="{{ $minutes }}">{{ $label }}</option>
                @endforeach
            </x-forms.select>
        </div>
    </div>
        <div x-cloak x-show="selectedUuid !== 'default'">
            <h3 class="pb-2">Upload</h3>
            <div
                class="rounded-lg border-2 border-dashed p-6 text-center transition-colors"
                :class="isDragging
                    ? 'border-blue-500 bg-blue-500/5'
                    : 'border-coolgray-300 hover:border-coolgray-400 dark:border-coolgray-400 dark:hover:border-coolgray-300'"
                @dragenter.prevent="isDragging = true"
                @dragover.prevent="isDragging = true"
                @dragleave.prevent="isDragging = false"
                @drop.prevent="handleDrop($event)">
                <input x-ref="fileUpload" id="file-upload" class="hidden" type="file" @change="handleFileSelection($event)">
                <button class="w-full cursor-pointer" type="button" @click="$refs.fileUpload.click()">
                    <div class="flex flex-col items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 text-neutral-400 dark:text-neutral-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                        </svg>
                        <div class="text-sm">
                            <span class="font-semibold text-blue-500 hover:text-blue-600">Click to upload</span>
                            <span class="text-neutral-600 dark:text-neutral-400"> or drag and drop</span>
                        </div>
                        <div class="text-xs text-neutral-500 dark:text-neutral-400">
                            Any file up to 10GB
                        </div>
                    </div>
                </button>
            </div>
        </div>

        <div x-cloak x-show="isUploading">
            <div class="pb-1 text-sm">Uploading: <span x-text="Math.round(progress)"></span>%</div>
            <progress max="100" x-bind:value="progress" class="progress progress-warning w-full"></progress>
        </div>

        <div x-cloak x-show="filename && !error && !filePath" class="rounded-sm p-4 dark:bg-coolgray-100 mt-4">
            <h3 class="pb-2">File Uploaded to Temporary Storage</h3>
            <div class="space-y-1 text-sm">
                <div><span class="font-semibold">Filename:</span> <span x-text="filename"></span></div>
                <div><span class="font-semibold">Size:</span> <span x-text="filesize"></span></div>
            </div>
            <x-forms.button class="mt-4 w-full" x-bind:disabled="isUploading || !hasPendingUpload || selectedUuid === 'default'" wire:click="generateFilePath" isHighlighted>
                Finalize Upload
            </x-forms.button>
        </div>

        <div x-cloak x-show="filePath" class="rounded-sm border border-success bg-success/10 p-4 mt-4">
            <h3 class="pb-2 text-success">File Ready!</h3>
            <div class="space-y-2">
                <div class="text-sm">
                    <span class="font-semibold">File Path:</span>
                    <div class="relative mt-1" x-data="{ copied: false }">
                        <code class="block rounded bg-coolgray-100 p-2 pr-12 text-xs dark:bg-coolgray-300" x-text="filePath"></code>
                        <button
                            @click.prevent="copied = true; navigator.clipboard.writeText(filePath); setTimeout(() => copied = false, 1000)"
                            class="absolute right-2 top-1/2 -translate-y-1/2 cursor-pointer p-1.5 text-gray-400 transition-colors hover:text-gray-300"
                            title="Copy to clipboard"
                            type="button">
                            <svg x-show="!copied" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                            </svg>
                            <svg x-show="copied" class="h-5 w-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                        </button>
                    </div>
                </div>
                <div class="text-sm">
                    <span class="font-semibold">Expires in:</span> {{ $expirationMinutes }} minutes
                </div>
                <div class="pt-2 text-xs text-neutral-600 dark:text-neutral-400">
                    Copy the file path above and use it in your terminal commands.
                    The file will be automatically deleted after expiration.
                </div>
            </div>
        </div>



        @if (count($this->uploadedFiles) > 0)
            <div class="pt-4">
                <h3 class="pb-2">Previously Uploaded Files</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-coolgray-300 dark:border-coolgray-600">
                            <tr class="text-left">
                                <th class="pb-2 pr-4">File Name</th>
                                <th class="pb-2 pr-4">Server</th>
                                <th class="pb-2 pr-4">Size</th>
                                <th class="pb-2 pr-4">Uploaded</th>
                                <th class="pb-2 pr-4">Expires</th>
                                <th class="pb-2">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->uploadedFiles as $file)
                                <tr wire:key="terminal-upload-file-{{ $file['uuid'] }}" class="border-b border-coolgray-200 dark:border-coolgray-700">
                                    <td class="py-3 pr-4">
                                        <div class="font-medium">{{ $file['display_name'] }}</div>
                                        <div class="text-xs text-neutral-500">{{ $file['filename'] }}</div>
                                    </td>
                                    <td class="py-3 pr-4">
                                        <div class="font-medium">{{ $file['server_name'] }}</div>
                                        @if ($file['container_uuid'])
                                            <div class="text-xs text-neutral-500">Container: {{ substr($file['container_uuid'], 0, 12) }}</div>
                                        @endif
                                    </td>
                                    <td class="py-3 pr-4">
                                        {{ formatBytes($file['size']) }}
                                    </td>
                                    <td class="py-3 pr-4">
                                        {{ \Carbon\Carbon::createFromTimestamp($file['uploaded_at'])->diffForHumans() }}
                                    </td>
                                    <td class="py-3 pr-4">
                                        @if ($file['expires_at'])
                                            @php
                                                $expiresAt = \Carbon\Carbon::createFromTimestamp($file['expires_at']);
                                                $now = \Carbon\Carbon::now();
                                            @endphp
                                            @if ($now->gt($expiresAt))
                                                <span class="text-red-500">Expired</span>
                                            @else
                                                <span>{{ $expiresAt->diffForHumans() }}</span>
                                            @endif
                                        @else
                                            <span class="text-neutral-500">N/A</span>
                                        @endif
                                    </td>
                                    <td class="py-3">
                                        <div class="flex gap-2">
                                            <x-forms.button
                                                class="cursor-pointer"
                                                title="Copy remote file path"
                                                wire:click="copyPath('{{ $file['uuid'] }}')">
                                                Copy Path
                                            </x-forms.button>
                                            <x-modal-confirmation
                                                title="Confirm Terminal File Deletion?"
                                                buttonTitle="Delete"
                                                isErrorButton
                                                submitAction="confirmDeleteFile"
                                                :actions="['The selected uploaded file will be permanently deleted from Coolify and any remote terminal target.']"
                                                confirmationText="{{ $file['display_name'] }}"
                                                confirmationLabel="Please confirm the deletion by entering the uploaded file name below"
                                                shortConfirmationLabel="Uploaded File Name"
                                                :confirmWithPassword="false"
                                                step2ButtonText="Delete File">
                                                <x-slot:trigger>
                                                    <x-forms.button
                                                        isError
                                                        class="cursor-pointer"
                                                        title="Delete file"
                                                        x-on:click="$wire.prepareFileDeletion('{{ $file['uuid'] }}')">
                                                        Delete
                                                    </x-forms.button>
                                                </x-slot:trigger>
                                            </x-modal-confirmation>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</div>
