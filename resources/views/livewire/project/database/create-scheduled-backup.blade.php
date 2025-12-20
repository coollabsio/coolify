<form class="flex flex-col w-full gap-2 rounded-sm" wire:submit='submit'>
    <x-forms.input placeholder="0 0 * * * or daily" id="frequency"
        helper="{{ __('backup.frequency_helper') }}" label="{{ __('backup.frequency') }}"
        required />
    <h2>S3</h2>
    @if ($definedS3s->count() === 0)
        <div class="text-red-500">{{ __('backup.no_s3_storages') }}</div>
    @else
        <x-forms.checkbox wire:model.live="saveToS3" label="{{ __('backup.save_to_s3') }}" />
        @if ($saveToS3)
            <x-forms.select id="s3StorageId" label="{{ __('backup.select_s3_storage') }}">
                @foreach ($definedS3s as $s3)
                    <option value="{{ $s3->id }}">{{ $s3->name }}</option>
                @endforeach
            </x-forms.select>
        @endif
    @endif
    <x-forms.button type="submit" @click="modalOpen=false">
        {{ __('button.save') }}
    </x-forms.button>
</form>
