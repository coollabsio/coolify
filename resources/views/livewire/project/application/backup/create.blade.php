<form class="flex flex-col w-full gap-4 rounded-sm" wire:submit="submit">
    @if ($volumes->isEmpty())
        <div class="text-warning">Add a persistent volume before configuring a backup.</div>
    @else
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <x-forms.select id="volumeId" label="Volume" required :disabled="$volumeLocked">
                @foreach ($volumes as $volume)
                    <option value="{{ $volume->id }}">{{ $volume->name }}</option>
                @endforeach
            </x-forms.select>
            <x-forms.input id="frequency" placeholder="0 0 * * * or daily"
                helper="Use every_minute, hourly, daily, weekly, monthly, yearly, or a cron expression."
                label="Frequency" required />
        </div>

        <div>
            <h2 class="pb-2">S3</h2>
            @if ($definedS3s->isEmpty())
                <div class="text-sm text-neutral-600 dark:text-neutral-400">No validated S3 storages found.</div>
            @else
                <div class="flex flex-col gap-3">
                    <x-forms.checkbox live id="saveToS3" label="Save to S3" />
                    @if ($saveToS3)
                        <x-forms.select id="s3StorageId" label="S3 Storage">
                            @foreach ($definedS3s as $s3)
                                <option value="{{ $s3->id }}">{{ $s3->name }}</option>
                            @endforeach
                        </x-forms.select>
                    @endif
                </div>
            @endif
        </div>

        <x-forms.button type="submit">Save</x-forms.button>
    @endif
</form>
