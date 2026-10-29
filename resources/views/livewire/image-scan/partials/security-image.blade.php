{{-- One image row of the Security main card (spec 15.11): the image and its digest, the status badge, the summary
     (sentence, notes, provenance, and the action), the known issues, and a menu with the links of the image. --}}
@php
    $muted = 'text-[11px] text-neutral-500 dark:text-fg-faint';
    $note = 'text-[12px] leading-5 text-neutral-600 dark:text-fg-dim';
    $filter = \Illuminate\Support\Js::from($row['filter'] ?? '');
    $refLength = strlen($row['ref']);
    $startsWithRef = $refLength > 0 && str_starts_with($row['sentence'], $row['ref']);
@endphp

<div wire:key="security-image-{{ $row['key'] }}" class="data-table-row image-scan-images-grid items-start! border-b border-neutral-200 last:border-b-0 dark:border-white/[0.06]" role="row">
    <div class="flex min-w-0 flex-col gap-0.5" role="cell">
        <span class="truncate text-[13px] font-medium text-neutral-950 dark:text-fg">{{ $row['name'] ?? $row['ref'] }}</span>
        @if ($row['name'] !== null)
            <code class="truncate font-mono text-[11px] text-neutral-500 dark:text-fg-faint" title="{{ $row['ref'] }}">{{ $row['ref'] }}</code>
        @endif
        @if ($row['digest'] !== null)
            <span class="flex min-w-0 items-center gap-1 {{ $muted }}">
                <code class="truncate font-mono" title="Running digest {{ $row['digestFull'] }}">{{ $row['digest'] }}</code>
                <x-copy-button :value="$row['digestFull']" label="Copy the digest of the running image" />
            </span>
        @endif
    </div>

    <div class="flex min-w-0" role="cell">
        @if ($row['badge'] !== null)
            <x-status-badge :type="$row['badgeType']" :label="$row['badge']" />
        @else
            <span class="text-[12px] text-neutral-500 dark:text-fg-faint">{{ $row['status'] === 'ok' ? 'No action needed' : 'Not checked' }}</span>
        @endif
    </div>

    <div class="image-scan-summary-cell flex min-w-0 max-w-2xl flex-col gap-2" role="cell">
        <div class="flex flex-col gap-1 text-pretty">
            <p class="text-[13px] leading-5 text-neutral-950 dark:text-fg">
                @if ($startsWithRef)
                    <code class="break-all font-mono text-[12px]">{{ $row['ref'] }}</code>{{ substr($row['sentence'], $refLength) }}
                @else
                    {{ $row['sentence'] }}
                @endif
            </p>
            @if ($row['attackNote'] !== null)
                <p class="{{ $note }}">{{ $row['attackNote'] }}</p>
            @endif
            @if ($row['advice'] !== null)
                <p class="{{ $note }}">{{ $row['advice'] }}</p>
            @endif
            @foreach ($row['lines'] as $line)
                <p class="{{ $note }}">{{ $line }}</p>
            @endforeach
            @foreach ($row['versionNotes'] as $versionNote)
                <p class="{{ $note }}">
                    {{ $versionNote['text'] }}
                    @if ($versionNote['link'] !== null)
                        <a href="{{ $versionNote['link'] }}" class="underline underline-offset-4 hover:text-neutral-950 dark:hover:text-fg" {{ wireNavigate() }}>Registry logins</a>
                    @endif
                </p>
            @endforeach
            @if ($row['provenance'] !== null)
                <p class="flex gap-1.5 {{ $muted }} text-[12px]! leading-5">
                    <span class="mt-[3px] shrink-0" title="How Coolify knows">
                        <x-reicon name="info-circle" class="size-3.5" />
                    </span>
                    <span><span class="sr-only">How Coolify knows: </span>{{ $row['provenance'] }}</span>
                </p>
            @endif
        </div>

        @if ($row['override'] !== null)
            {{-- A security update of a Compose app: Coolify replaces the image of the compose file from Git. --}}
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 {{ $muted }} text-[12px]!">
                <span>
                    Coolify deploys <code class="font-mono">{{ $row['override']['to'] }}</code> instead of
                    <code class="font-mono">{{ $row['override']['from'] }}</code> from your compose file.
                </span>
                @if ($canUpdate)
                    <button type="button" class="font-medium text-neutral-600 underline-offset-4 hover:text-neutral-950 hover:underline dark:text-fg-dim dark:hover:text-fg"
                        wire:click="removeImageOverride({{ \Illuminate\Support\Js::from($row['override']['service']) }})"
                        title="The next deploy uses the image of the compose file again.">Use the compose file</button>
                @endif
            </div>
        @endif

        @if ($row['mergedWith'] !== null)
            <p class="{{ $muted }}">Updated with {{ $row['mergedWith'] }}.</p>
        @elseif ($row['primary'] !== null)
            <div class="flex">
                @include('livewire.image-scan.partials.security-action', ['action' => $row['primary'], 'primary' => true, 'withSentence' => false, 'keyPrefix' => $row['key']])
            </div>
        @endif

        @if ($row['others'] !== [])
            <details class="group/others">
                <summary class="cursor-pointer list-none text-[12px] font-medium text-neutral-600 hover:text-neutral-950 dark:text-fg-dim dark:hover:text-fg">
                    {{ count($row['others']) + $row['moreActions'] }} other {{ count($row['others']) + $row['moreActions'] === 1 ? 'fix' : 'fixes' }}
                </summary>
                <div class="mt-2 flex flex-col gap-2">
                    @foreach ($row['others'] as $other)
                        @include('livewire.image-scan.partials.security-action', ['action' => $other, 'primary' => false, 'withSentence' => true, 'keyPrefix' => $row['key']])
                    @endforeach
                    @if ($row['moreActions'] > 0)
                        <p class="{{ $muted }}">and {{ $row['moreActions'] }} more</p>
                    @endif
                </div>
            </details>
        @endif
    </div>

    <div class="image-scan-secondary flex justify-end" role="cell">
        @if (($row['issues'] ?? 0) > 0)
            <button type="button" class="text-[13px] font-medium tabular-nums text-neutral-950 underline-offset-4 hover:underline dark:text-fg"
                @if ($row['filter'] !== null) wire:click="showImageFindings({{ $filter }})" @endif
                x-on:click="$dispatch('open-settings-section', { id: 'all-findings-section' })"
                aria-label="Show the {{ $row['known'] }} of {{ $row['ref'] }}">{{ number_format($row['issues']) }}</button>
        @elseif ($row['issues'] !== null)
            <span class="text-[13px] tabular-nums text-neutral-400 dark:text-fg-faint">0</span>
        @endif
    </div>

    <div class="flex justify-end" role="cell">
        @if ($row['digestFull'] !== null)
            <x-table.dropdown role="menu" panel-class="min-w-48! p-1!">
                <x-slot:trigger>
                    <button type="button" class="icon-button" aria-haspopup="menu" :aria-expanded="open"
                        aria-label="More for {{ $row['ref'] }}">
                        <x-reicon name="dots-horizontal" class="size-4" />
                    </button>
                </x-slot:trigger>
                <button type="button" class="listbox-option" role="menuitem"
                    @if ($row['filter'] !== null) wire:click="showImageFindings({{ $filter }})" @endif
                    x-on:click="close(); $dispatch('open-settings-section', { id: 'all-findings-section' })">All findings</button>
                <button type="button" class="listbox-option" role="menuitem"
                    x-on:click="close(); $dispatch('security-show-image-packages', { image: {{ $filter }} }); $dispatch('open-settings-section', { id: 'packages-section' })">Packages and SBOM</button>
                @if ($hasSettings)
                    <button type="button" class="listbox-option" role="menuitem"
                        x-on:click="close(); $dispatch('open-settings-section', { id: 'image-scan-settings-section' })">Scan settings</button>
                @endif
            </x-table.dropdown>
        @endif
    </div>
</div>
