<div>
    <h2>S3 Test</h2>
    <form wire:submit="save">
        <input type="file" wire:model="file">
        @error('file')
            <span class="error">{{ $message }}</span>
        @enderror
        <div wire:loading wire:target="file">Uploading to server...</div>
        @if ($file)
            <x-forms.button type="submit">Upload file to s3:/files</x-forms.button>
        @endif
    </form>
    <h4>Functions</h4>
    <x-forms.button wire:click="get_files">Get s3:/files</x-forms.button>
</div>
