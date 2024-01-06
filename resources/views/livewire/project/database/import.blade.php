<div>
    <div class="mb-10 rounded alert alert-warning">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 stroke-current shrink-0" fill="none"
            viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
        </svg>
        <span>This is a destructive action, existing data will be replaced!</span>
    </div>

    @if (!$validated)
        <div>{{ $validationMsg }}</div>
    @else
        @if (!$importRunning)
        <form disabled wire:submit.prevent="runImport">
            <div class="flex items-end gap-2"
                x-data="{ isFinished: false, isUploading: false, progress: 0 }"
                x-on:livewire-upload-start="isUploading = true; isFinished = false"
                x-on:livewire-upload-finish="isUploading = false; isFinished = true"
                x-on:livewire-upload-error="isUploading = false"
                x-on:livewire-upload-progress="progress = $event.detail.progress"
            >
                <input type="file" id="file" wire:model="file">
                @error('file') <span class="error">{{ $message }}</span> @enderror
                <x-forms.button type="submit" x-show="isFinished">Import</x-forms.button>
                <div x-show="isUploading">
                    <progress max="100" x-bind:value="progress"></progress>
                </div>
            </div>
        </form>
        @endif
    @endif

    @if ($scpInProgress)
        <div>Database backup is being copied to server..</div>
    @endif

    <div class="container w-full pt-10 mx-auto">
        <livewire:activity-monitor header="Database import output" />
    </div>
</div>
