<div x-data="{ error: $wire.entangle('error'), filesize: $wire.entangle('filesize'), filename: $wire.entangle('filename'), isUploading: $wire.entangle('isUploading'), progress: $wire.entangle('progress'), filePath: $wire.entangle('filePath') }">

    <div class="pb-4">
        <h2>Import File for Terminal</h2>
        <div class="text-sm text-neutral-500 pb-2">
            Upload a file that will be temporarily stored and accessible in your selected server or container.
            Perfect for importing SQL dumps, configuration files, or any other data.
        </div>
    </div>

    <div class="space-y-4">
        <!-- Target Display -->
        @if ($targetName)
            <div class="rounded-sm bg-coolgray-100 dark:bg-coolgray-200 p-3">
                <div class="text-sm">
                    <span class="font-semibold">Target:</span> {{ $targetName }}
                </div>
            </div>
        @endif

        <!-- Expiration Time Selection -->
        <div class="w-full lg:w-64">
            <x-forms.select 
                id="expirationMinutes" 
                label="File Expiration" 
                wire:model="expirationMinutes">
                @foreach ($this->expirationOptions as $minutes => $label)
                    <option value="{{ $minutes }}">{{ $label }}</option>
                @endforeach
            </x-forms.select>
            <div class="text-xs text-neutral-500 mt-1">
                File will be automatically deleted after this time for security.
            </div>
        </div>

        <!-- File Upload -->
        <div>
            <h3 class="pb-2">Upload File</h3>
            <div class="border-2 border-dashed border-coolgray-300 dark:border-coolgray-400 rounded-lg p-6 text-center hover:border-coolgray-400 dark:hover:border-coolgray-300 transition-colors">
                <input 
                    type="file" 
                    wire:model="uploadedFile" 
                    id="file-upload"
                    class="hidden"
                    @change="
                        const file = $event.target.files[0];
                        if (file) {
                            filename = file.name;
                            filesize = Number(file.size / 1024 / 1024).toFixed(2) + ' MB';
                        }
                    "
                >
                <label for="file-upload" class="cursor-pointer">
                    <div class="flex flex-col items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-12 w-12 text-coolgray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                        </svg>
                        <div class="text-sm text-coolgray-500">
                            <span class="font-semibold text-blue-500 hover:text-blue-600">Click to upload</span>
                            or drag and drop
                        </div>
                        <div class="text-xs text-coolgray-400">
                            Any file up to 10GB
                        </div>
                    </div>
                </label>
            </div>
        </div>

        <!-- Upload Progress -->
        <div x-show="isUploading" x-cloak>
            <div class="text-sm pb-1">Uploading: <span x-text="Math.round(progress)"></span>%</div>
            <progress max="100" x-bind:value="progress" class="progress progress-warning w-full"></progress>
        </div>

        <!-- File Information -->
        <div x-show="filename && !error && !filePath" x-cloak class="rounded-sm bg-coolgray-100 dark:bg-coolgray-200 p-4">
            <h3 class="pb-2">File Uploaded</h3>
            <div class="space-y-1 text-sm">
                <div><span class="font-semibold">Filename:</span> <span x-text="filename"></span></div>
                <div><span class="font-semibold">Size:</span> <span x-text="filesize"></span></div>
            </div>
            <x-forms.button 
                class="mt-4 w-full" 
                wire:click='generateFilePath'>
                Generate File Path & Copy to Target
            </x-forms.button>
        </div>

        <!-- File Path Result -->
        <div x-show="filePath" x-cloak class="rounded-sm bg-success/10 border border-success p-4">
            <h3 class="pb-2 text-success">File Ready!</h3>
            <div class="space-y-2">
                <div class="text-sm">
                    <span class="font-semibold">File Path:</span>
                    <code class="block mt-1 p-2 bg-coolgray-100 dark:bg-coolgray-300 rounded text-xs" x-text="filePath"></code>
                </div>
                <div class="text-sm">
                    <span class="font-semibold">Expires in:</span> {{ $expirationMinutes }} minutes
                </div>
                <div class="text-xs text-neutral-600 dark:text-neutral-400 pt-2">
                    Copy the file path above and use it in your terminal commands.
                    The file will be automatically deleted after expiration.
                </div>
            </div>
        </div>

        <!-- Security Notice -->
        <div class="rounded-sm alert-warning text-sm">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 stroke-current shrink-0" fill="none"
                viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <div>
                <div class="font-semibold">Security Notice</div>
                <div class="text-xs mt-1">
                    Uploaded files are stored temporarily and will be automatically deleted after the expiration time.
                    Do not upload sensitive files without encryption. Always verify file permissions after upload.
                </div>
            </div>
        </div>
    </div>
</div>
