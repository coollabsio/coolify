<form class="flex flex-col w-full gap-4 rounded-sm" wire:submit="submit">
    @if ($targets->isEmpty())
        <div class="text-warning">Add a persistent volume or directory mount before configuring a backup.</div>
    @else
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <x-forms.select id="targetKey" label="Backup Target" required :disabled="$targetLocked">
                @foreach ($targets as $target)
                    <option value="{{ $target['key'] }}">{{ $target['type'] }}: {{ $target['name'] }}</option>
                @endforeach
            </x-forms.select>
            <x-forms.input id="frequency" placeholder="0 0 * * * or daily"
                helper="Use every_minute, hourly, daily, weekly, monthly, yearly, or a cron expression."
                label="Frequency" required />
        </div>

        <x-forms.button type="submit">Save</x-forms.button>
    @endif
</form>
