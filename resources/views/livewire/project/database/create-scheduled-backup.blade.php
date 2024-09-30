<form class="flex flex-col w-full gap-2 rounded" wire:submit='submit'>
    <x-forms.input autofocus placeholder="0 0 * * * or daily" id="frequency"
        helper="You can use every_minute, hourly, daily, weekly, monthly, yearly or a cron expression." label="Frequency"
        required />
    <x-forms.checkbox id="save_s3" label="Save to S3" />
    <x-forms.select id="selected_storage_id">
        @if ($s3s->count() === 0)
            <option value="0">No S3 Storages found.</option>
        @else
            @foreach ($s3s as $s3)
                <option value="{{ $s3->id }}">{{ $s3->name }}</option>
            @endforeach
        @endif
    </x-forms.select>
    <x-forms.button type="submit" @click="modalOpen=false">
        Save
    </x-forms.button>
</form>
