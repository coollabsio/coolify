{{-- The open content of one image row of the Security main card (spec 15.11): sentence, attack note, action (single
     image only; a list shows it in the row header), why-list, provenance, other fixes, and the running image. --}}
@php
    $muted = 'text-[11px] text-neutral-500 dark:text-fg-faint';
    $link = 'text-[12px] font-medium text-coollabs hover:underline dark:text-brand-light';
    $refLength = strlen($row['ref']);
    $startsWithRef = $refLength > 0 && str_starts_with($row['sentence'], $row['ref']);
    $whyTitle = $row['status'] === 'no_fix' ? 'Not fixed yet' : 'Fixed by this update';
@endphp

<div class="flex flex-col gap-4">
    <div class="flex max-w-3xl flex-col gap-1">
        <p class="text-[14px] font-medium leading-6 text-neutral-950 dark:text-fg">
            @if ($startsWithRef)
                <code class="break-all font-mono text-[13px]">{{ $row['ref'] }}</code>{{ substr($row['sentence'], $refLength) }}
            @else
                {{ $row['sentence'] }}
            @endif
        </p>
        @if ($row['attackNote'] !== null)
            <p class="text-[13px] leading-5 text-neutral-600 dark:text-fg-dim">{{ $row['attackNote'] }}</p>
        @endif
        @if ($row['advice'] !== null)
            <p class="text-[13px] leading-5 text-neutral-600 dark:text-fg-dim">{{ $row['advice'] }}</p>
        @endif
        @foreach ($row['lines'] as $line)
            <p class="text-[13px] leading-5 text-neutral-600 dark:text-fg-dim">{{ $line }}</p>
        @endforeach
        @foreach ($row['versionNotes'] as $note)
            <p class="text-[13px] leading-5 text-neutral-600 dark:text-fg-dim">
                {{ $note['text'] }}
                @if ($note['link'] !== null)
                    <a href="{{ $note['link'] }}" class="underline underline-offset-4 hover:text-neutral-950 dark:hover:text-fg" {{ wireNavigate() }}>Registry logins</a>
                @endif
            </p>
        @endforeach
    </div>

    @if ($single)
        @if ($row['status'] === 'check_needed')
            @if ($canUpdate)
                <div>
                    <x-forms.button isHighlighted class="normal-case!" wire:click="scanNow" :disabled="$isScanning">
                        <x-reicon name="refresh" class="size-3.5" />
                        Scan now
                    </x-forms.button>
                </div>
            @endif
        @elseif ($row['primary'] !== null)
            @include('livewire.image-scan.partials.security-action', ['action' => $row['primary'], 'primary' => true, 'withSentence' => false, 'keyPrefix' => $row['key']])
        @endif
    @endif

    @if ($row['why'] !== [])
        <div class="flex flex-col gap-2">
            <h4 class="text-[12px] font-medium text-neutral-700 dark:text-fg-dim">{{ $whyTitle }}</h4>
            <ul class="flex flex-col divide-y divide-neutral-200 overflow-hidden rounded-lg border border-neutral-200 dark:divide-white/[0.06] dark:border-white/[0.08]"
                aria-label="{{ $whyTitle }}">
                @foreach ($row['why'] as $entry)
                    <li wire:key="security-why-{{ $row['key'] }}-{{ md5($entry['display_id'].$entry['title']) }}"
                        class="flex flex-col gap-0.5 px-3 py-2.5">
                        <div class="flex min-w-0 items-baseline justify-between gap-4">
                            <span class="min-w-0 text-[13px] leading-5 text-neutral-900 dark:text-fg">{{ $entry['title'] }}</span>
                            <span class="shrink-0 text-[12px] tabular-nums text-neutral-600 dark:text-fg-dim">{{ $entry['score'] }}</span>
                        </div>
                        {{-- Each separator stays with the part after it, so a wrapped line never ends with "·". On narrow
                             screens the package gets its own line and no separator after it. --}}
                        <div class="flex min-w-0 flex-wrap items-center gap-x-1.5 {{ $muted }}">
                            @if ($entry['package'] !== '')
                                <span class="min-w-0 break-all font-mono max-sm:w-full">{{ trim($entry['package'].' '.$entry['version']) }}</span>
                            @endif
                            <span class="inline-flex items-center gap-1.5 whitespace-nowrap">
                                @if ($entry['package'] !== '')
                                    <span class="max-sm:hidden" aria-hidden="true">·</span>
                                @endif
                                @if ($entry['link'] !== null)
                                    <a href="{{ $entry['link'] }}" target="_blank" rel="noopener noreferrer"
                                        class="font-mono underline-offset-4 hover:text-black hover:underline dark:hover:text-fg">{{ $entry['display_id'] }}</a>
                                @else
                                    <span class="font-mono">{{ $entry['display_id'] }}</span>
                                @endif
                            </span>
                            @if ($entry['signal'] !== null)
                                <span class="inline-flex items-center gap-1.5 whitespace-nowrap">
                                    <span aria-hidden="true">·</span>
                                    <span @class(['text-error' => $entry['signal'] === 'Used by attackers'])>{{ $entry['signal'] }}</span>
                                </span>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
            @if ($row['whyMore'] !== '')
                <p class="{{ $muted }} text-[12px]!">{{ $row['whyMore'] }}</p>
            @endif
        </div>
    @endif

    @if ($row['provenance'] !== null)
        <p class="max-w-3xl text-[12px] leading-5 text-neutral-500 dark:text-fg-faint">
            <span class="font-medium text-neutral-700 dark:text-fg-dim">How Coolify knows:</span>
            {{ $row['provenance'] }}
        </p>
    @endif

    @if ($row['others'] !== [])
        <div class="flex flex-col gap-2">
            <h4 class="text-[12px] font-medium text-neutral-700 dark:text-fg-dim">Other fixes</h4>
            <div class="flex flex-col divide-y divide-neutral-200 rounded-lg border border-neutral-200 dark:divide-white/[0.06] dark:border-white/[0.08]">
                @foreach ($row['others'] as $other)
                    <div class="px-3 py-2.5">
                        @include('livewire.image-scan.partials.security-action', ['action' => $other, 'primary' => false, 'withSentence' => true, 'keyPrefix' => $row['key']])
                    </div>
                @endforeach
            </div>
            @if ($row['moreActions'] > 0)
                <p class="{{ $muted }} text-[12px]!">and {{ $row['moreActions'] }} more</p>
            @endif
        </div>
    @endif

    @if ($row['digestFull'] !== null)
        <div class="flex flex-col gap-2 border-t border-neutral-200 pt-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between dark:border-white/[0.08]">
            <div class="flex min-w-0 flex-wrap items-center gap-x-1.5 gap-y-1 text-[12px] text-neutral-600 dark:text-fg-dim">
                <span class="inline-flex min-w-0 items-center gap-1.5">
                    <span>Running</span>
                    <code class="min-w-0 truncate font-mono text-neutral-800 dark:text-fg" title="{{ $row['ref'] }}">{{ $row['ref'] }}</code>
                    @if ($row['digest'] !== null)
                        <code class="font-mono text-neutral-500 dark:text-fg-faint" title="{{ $row['digestFull'] }}">{{ $row['digest'] }}</code>
                        <x-copy-button :value="$row['digestFull']" label="Copy the digest of the running image" />
                    @endif
                </span>
                @if ($row['known'] !== '')
                    <span class="inline-flex items-center gap-1.5 whitespace-nowrap max-sm:w-full">
                        <span class="max-sm:hidden" aria-hidden="true">·</span>
                        <span>{{ $row['known'] }}</span>
                    </span>
                @endif
            </div>
            @php
                $filter = \Illuminate\Support\Js::from($row['filter'] ?? '');
            @endphp
            <div class="flex flex-wrap items-center gap-3">
                <button type="button" class="{{ $link }}"
                    @if ($row['filter'] !== null) wire:click="showImageFindings({{ $filter }})" @endif
                    x-on:click="$dispatch('open-settings-section', { id: 'all-findings-section' })">All findings</button>
                <button type="button" class="{{ $link }}"
                    x-on:click="$dispatch('security-show-image-packages', { image: {{ $filter }} }); $dispatch('open-settings-section', { id: 'packages-section' })">Packages and SBOM</button>
                @if ($hasSettings)
                    <button type="button" class="{{ $link }}"
                        x-on:click="$dispatch('open-settings-section', { id: 'image-scan-settings-section' })">Settings</button>
                @endif
            </div>
        </div>
    @endif
</div>
