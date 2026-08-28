<div class="flex flex-col gap-6">
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-base font-semibold text-black dark:text-fg">Template</h2>
            <p class="mt-1 text-sm text-neutral-500 dark:text-fg-dim">
                Review and adopt changes from the latest version of this one-click template.
            </p>
        </div>
        @if ($this->template === null)
            <span class="text-sm text-neutral-500 dark:text-fg-dim">No template linked</span>
        @elseif ($this->updateAvailable)
            <span class="rounded-full bg-warning/10 px-3 py-1 text-sm text-warning">Update available</span>
        @else
            <span class="rounded-full bg-success/10 px-3 py-1 text-sm text-success">Up to date</span>
        @endif
    </div>

    @if ($this->template === null)
        <div class="text-sm text-neutral-500 dark:text-fg-dim">
            This service is not linked to a known template, so there is nothing to compare.
        </div>
    @else
        {{-- Compose diff --}}
        <section class="flex flex-col gap-2">
            <h3 class="text-sm font-semibold">Compose changes</h3>
            @forelse ($this->hunks as $hunk)
                <div class="rounded border border-neutral-200 dark:border-white/[0.06]">
                    <label class="flex items-center gap-2 border-b border-neutral-200 px-3 py-2 dark:border-white/[0.06]">
                        <input type="checkbox" wire:model.live="acceptedHunks" value="{{ $hunk['index'] }}" />
                        <span class="text-xs text-neutral-500">Change #{{ $hunk['index'] + 1 }}</span>
                    </label>
                    <pre class="overflow-x-auto p-3 text-xs leading-5">@foreach ($hunk['lines'] as $line)<div @class([
    'bg-success/10 text-success' => $line['type'] === 'add',
    'bg-error/10 text-error' => $line['type'] === 'remove',
    'text-neutral-500' => $line['type'] === 'context',
])>{{ $line['type'] === 'add' ? '+' : ($line['type'] === 'remove' ? '-' : ' ') }} {{ $line['text'] }}</div>@endforeach</pre>
                </div>
            @empty
                <p class="text-sm text-neutral-500 dark:text-fg-dim">Your compose matches the latest template.</p>
            @endforelse
        </section>

        {{-- Env diff --}}
        <section class="flex flex-col gap-2">
            <h3 class="text-sm font-semibold">Environment variable changes</h3>
            @foreach (['new' => 'New', 'changed' => 'Changed default'] as $bucket => $label)
                @foreach ($this->envDiff[$bucket] as $item)
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model.live="acceptedEnvKeys" value="{{ $item['key'] }}" />
                        <span class="font-mono">{{ $item['key'] }}</span>
                        <span class="text-xs text-neutral-500">{{ $label }}</span>
                    </label>
                @endforeach
            @endforeach
            @foreach ($this->envDiff['removed'] as $item)
                <div class="flex items-center gap-2 text-sm text-neutral-500">
                    <span class="font-mono">{{ $item['key'] }}</span>
                    <span class="text-xs">Removed from template (kept — no action)</span>
                </div>
            @endforeach
        </section>

        {{-- Actions --}}
        <div class="flex items-center gap-3">
            <x-forms.button wire:click="apply">Apply selected changes</x-forms.button>
            <x-forms.button wire:click="replaceAll"
                wire:confirm="Replace your entire compose with the latest template? Your compose customizations will be lost.">
                Replace entire compose with latest
            </x-forms.button>
            @if ($this->updateAvailable)
                <button type="button" wire:click="dismiss" class="text-sm text-neutral-500 hover:underline">Dismiss this update</button>
            @endif
        </div>
    @endif
</div>
