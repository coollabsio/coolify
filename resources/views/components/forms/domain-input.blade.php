@props([
    'id',
    'errorId' => null,
    'hostLabel' => 'Domain',
    'hostPlaceholder' => 'app.example.com',
])

<div class="grid gap-4 sm:grid-cols-[8rem_minmax(0,1fr)_8rem]">
    <div class="min-w-0">
        <x-forms.listbox id="{{ $id }}.scheme" htmlId="{{ $id }}-protocol" label="Protocol" portal :options="[
            ['value' => 'https', 'label' => 'https'],
            ['value' => 'http', 'label' => 'http'],
        ]" />
    </div>

    <div class="min-w-0">
        <div class="mb-1.5 flex h-4 w-full items-center gap-1.5">
            <label for="{{ $id }}-host" class="mb-0! flex items-center gap-1.5 leading-4">
                {{ $hostLabel }} <x-highlighted text="*" />
            </label>
        </div>
        <input id="{{ $id }}-host" type="text" class="input" wire:model="{{ $id }}.host"
            placeholder="{{ $hostPlaceholder }}" autocomplete="off" required />
        @error($errorId ?? "{$id}.host")
            @php
                preg_match('/(https?:\/\/\S+)$/', $message, $validationLinkMatches);
                $validationLink = $validationLinkMatches[1] ?? null;
            @endphp
            <p class="mt-1 text-[12px] text-red-500">
                @if ($validationLink)
                    {{ str($message)->beforeLast($validationLink)->trim() }}
                    <a class="font-medium underline" href="{{ $validationLink }}">Set them here.</a>
                @else
                    {{ $message }}
                @endif
            </p>
        @enderror
    </div>

    <div class="min-w-0">
        <div class="mb-1.5 flex h-4 w-full items-center gap-1.5">
            <label for="{{ $id }}-port" class="mb-0! flex items-center gap-1.5 leading-4">Port</label>
        </div>
        <input id="{{ $id }}-port" type="number" class="input" wire:model="{{ $id }}.port"
            placeholder="3000" min="1" max="65535" inputmode="numeric" />
    </div>

    <div class="min-w-0 sm:col-span-3">
        <div class="mb-1.5 flex h-4 w-full items-center gap-1.5">
            <label for="{{ $id }}-path" class="mb-0! flex items-center gap-1.5 leading-4">Path</label>
        </div>
        <input id="{{ $id }}-path" type="text" class="input" wire:model="{{ $id }}.path"
            placeholder="/api/v3" autocomplete="off" />
        <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">
            Optional path, query, or fragment appended after the domain and port.
        </p>
    </div>
</div>
