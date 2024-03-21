<div>
    <h2>Import Backup</h2>
    <div class="mt-2 mb-4 rounded alert-error">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 stroke-current shrink-0" fill="none" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
        </svg>
        <span>This is a destructive action, existing data will be replaced!</span>
    </div>

    @if (str(data_get($resource, 'status'))->startsWith('running'))
        @if (!$validated)
            <div>{{ $validationMsg }}</div>
        @else
            <form disabled wire:submit.prevent="runImport" x-data="{ isFinished: false, isUploading: false, progress: 0 }">
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
                <div x-on:livewire-upload-start="isUploading = true; isFinished = false"
                    x-on:livewire-upload-finish="isUploading = false; isFinished = true"
                    x-on:livewire-upload-error="isUploading = false"
                    x-on:livewire-upload-progress="progress = $event.detail.progress">
                    <label class="block mb-2 text-sm font-medium text-gray-900 dark:text-white" for="file_input">Upload
                        file</label>
                    <input wire:model="file"
                        class="block w-full text-sm rounded cursor-pointer text-whiteborder bg-coolgray-100 border-coolgray-400 focus:outline-none"
                        aria-describedby="file_input_help" id="file_input" type="file">
                    <p class="mt-1 text-sm text-neutral-500" id="file_input_help">Max file size: 256MB
                    </p>

                    @error('file')
                        <span class="error">{{ $message }}</span>
                    @enderror
                    <div x-show="isUploading">
                        <progress max="100" x-bind:value="progress"
                            class="progress progress-warning"></progress>
                    </div>
                </div>
                <x-forms.button type="submit" class="w-full mt-4" x-show="isFinished">Import Backup</x-forms.button>
            </form>
        @endif

        @if ($scpInProgress)
            <div>Database backup is being copied to server...</div>
        @endif

        <div class="container w-full pt-4 mx-auto">
            <livewire:activity-monitor header="Database import output" />
        </div>
    @else
        <div>Database must be running to import a backup.</div>
    @endif
</div>
