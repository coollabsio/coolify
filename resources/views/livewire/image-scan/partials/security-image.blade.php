{{-- The open content of one image of the Security main card (spec 15.11): the sentence, the primary action (one image
     only; a list shows it in the row header), the attack note, the why-list "Fixed by this update", the provenance, the
     other fixes, and the footer with the running image and its links. --}}
@php
    $muted = 'text-[11px] text-neutral-500 dark:text-fg-faint';
    $note = 'text-[13px] leading-5 text-neutral-600 dark:text-fg-dim';
    $link = 'font-medium text-neutral-800 underline decoration-neutral-400/40 underline-offset-4 transition-colors hover:text-neutral-950 hover:decoration-current dark:text-fg dark:decoration-white/25 dark:hover:decoration-current';
    $motion = 'transition-transform duration-[180ms] ease-[cubic-bezier(0.23,1,0.32,1)] motion-reduce:transition-none';
    $refLength = strlen($row['ref']);
    $startsWithRef = $refLength > 0 && str_starts_with($row['sentence'], $row['ref']);
    $whyTitle = $row['status'] === 'no_fix' ? 'Not fixed yet' : 'Fixed by this update';
    $filter = \Illuminate\Support\Js::from($row['filter'] ?? '');
    // Alpine expressions; the only value in them is the image ref, encoded by Js::from().
    $openFindings = new \Illuminate\Support\HtmlString(($row['filter'] !== null ? "\$wire.showImageFindings({$filter}); " : '')."\$dispatch('open-settings-section', { id: 'all-findings-section' })");
    $openPackages = new \Illuminate\Support\HtmlString("\$dispatch('security-show-image-packages', { image: {$filter} }); \$dispatch('open-settings-section', { id: 'packages-section' })");
@endphp

<div class="flex flex-col gap-4">
    <div class="flex max-w-3xl flex-col gap-3">
        <p class="text-pretty text-[15px] font-medium leading-6 text-neutral-950 dark:text-fg">
            @if ($startsWithRef)
                <code class="break-all font-mono text-[14px]">{{ $row['ref'] }}</code>{{ substr($row['sentence'], $refLength) }}
            @else
                {{ $row['sentence'] }}
            @endif
        </p>

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

        @if ($row['attackNote'] !== null || $row['advice'] !== null || $row['lines'] !== [] || ($single && $row['versionNotes'] !== []))
            <div class="flex flex-col gap-1">
                @if ($row['attackNote'] !== null)
                    <p class="{{ $note }}">{{ $row['attackNote'] }}</p>
                @endif
                @if ($row['advice'] !== null)
                    <p class="{{ $note }}">{{ $row['advice'] }}</p>
                @endif
                @foreach ($row['lines'] as $line)
                    <p class="{{ $note }}">{{ $line }}</p>
                @endforeach
                {{-- In a list, the row header shows these notes. --}}
                @foreach ($single ? $row['versionNotes'] : [] as $versionNote)
                    <p class="{{ $note }}">
                        {{ $versionNote['text'] }}
                        @if ($versionNote['link'] !== null)
                            <a href="{{ $versionNote['link'] }}" class="{{ $link }}" {{ wireNavigate() }}>Registry logins</a>
                        @endif
                    </p>
                @endforeach
            </div>
        @endif
    </div>

    @if ($row['override'] !== null)
        {{-- A security update of a Compose app: Coolify replaces the image of the compose file from Git. --}}
        <p class="max-w-3xl text-[12px] leading-5 text-neutral-500 dark:text-fg-faint">
            Coolify deploys <code class="font-mono">{{ $row['override']['to'] }}</code> instead of
            <code class="font-mono">{{ $row['override']['from'] }}</code> from your compose file.
            @if ($canUpdate)
                <button type="button" class="{{ $link }}"
                    wire:click="removeImageOverride({{ \Illuminate\Support\Js::from($row['override']['service']) }})"
                    title="The next deploy uses the image of the compose file again.">Use the compose file</button>
            @endif
        </p>
    @endif

    @if ($row['why'] !== [])
        <div class="flex flex-col gap-2">
            <h4 class="text-[12px] font-medium text-neutral-700 dark:text-fg-dim">{{ $whyTitle }}</h4>
            <ul class="flex flex-col divide-y divide-neutral-200 overflow-hidden rounded-lg border border-neutral-200 dark:divide-white/[0.06] dark:border-white/[0.08]"
                aria-label="{{ $whyTitle }}">
                @foreach ($row['why'] as $entry)
                    <li wire:key="security-why-{{ $row['key'] }}-{{ md5($entry['display_id'].$entry['title']) }}"
                        class="flex flex-col gap-1 px-3 py-2.5">
                        <div class="flex min-w-0 flex-col gap-1.5 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                            <span class="min-w-0 text-[13px] leading-5 text-neutral-900 dark:text-fg">{{ $entry['title'] }}</span>
                            <span class="flex shrink-0 items-center gap-1.5">
                                @if ($entry['cvss'] !== null)
                                    <x-image-scan.severity-badge :severity="$entry['severity']" />
                                    <span class="text-[12px] tabular-nums text-neutral-600 dark:text-fg-dim">{{ $entry['cvss'] }}</span>
                                @elseif ($entry['cvssNote'] !== null)
                                    <span data-tooltip="{{ $entry['cvssNote'] }}" title="{{ $entry['cvssNote'] }}">
                                        <x-image-scan.severity-badge :severity="$entry['severity']" />
                                    </span>
                                @else
                                    <x-image-scan.severity-badge :severity="$entry['severity']" />
                                @endif
                            </span>
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
                                        class="font-mono underline decoration-neutral-400/40 underline-offset-4 hover:text-neutral-950 hover:decoration-current dark:decoration-white/25 dark:hover:text-fg">{{ $entry['display_id'] }}</a>
                                @else
                                    <span class="font-mono">{{ $entry['display_id'] }}</span>
                                @endif
                            </span>
                            @if ($entry['signal'] !== null)
                                <span class="inline-flex items-center gap-1.5 whitespace-nowrap">
                                    <span aria-hidden="true">·</span>
                                    <span @class(['text-error' => $entry['exploited']])>{{ $entry['signal'] }}</span>
                                </span>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
            @if ($row['whyMore'] > 0)
                <div>
                    <button type="button" class="text-[12px] {{ $link }}" x-on:click="{{ $openFindings }}">
                        {{ number_format($row['whyMore']) }} more {{ $row['whyMore'] === 1 ? 'problem' : 'problems' }} this update fixes <span aria-hidden="true">→</span>
                    </button>
                </div>
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
        @php
            $otherCount = count($row['others']) + $row['moreActions'];
        @endphp
        <details class="group/others">
            <summary class="inline-flex cursor-pointer list-none items-center gap-1.5 rounded-md text-[12px] font-medium text-neutral-600 hover:text-neutral-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-coollabs dark:text-fg-dim dark:hover:text-fg dark:focus-visible:outline-brand [&::-webkit-details-marker]:hidden">
                <x-reicon name="chevron-down" class="size-3.5 shrink-0 -rotate-90 group-open/others:rotate-0 {{ $motion }}" />
                {{ number_format($otherCount) }} other {{ $otherCount === 1 ? 'fix' : 'fixes' }}
            </summary>
            <div class="mt-2 flex flex-col divide-y divide-neutral-200 rounded-lg border border-neutral-200 dark:divide-white/[0.06] dark:border-white/[0.08]">
                @foreach ($row['others'] as $other)
                    <div class="px-3 py-2.5">
                        @include('livewire.image-scan.partials.security-action', ['action' => $other, 'primary' => false, 'withSentence' => true, 'keyPrefix' => $row['key']])
                    </div>
                @endforeach
            </div>
            @if ($row['moreActions'] > 0)
                <p class="mt-2 {{ $muted }} text-[12px]!">and {{ number_format($row['moreActions']) }} more</p>
            @endif
        </details>
    @endif

    @if ($row['digestFull'] !== null)
        <div class="flex min-w-0 flex-wrap items-center gap-x-1.5 gap-y-1 border-t border-neutral-200 pt-3 text-[12px] text-neutral-600 dark:border-white/[0.08] dark:text-fg-dim">
            <span class="inline-flex min-w-0 max-w-full items-center gap-1.5">
                <span>Running</span>
                <code class="min-w-0 truncate font-mono text-neutral-800 dark:text-fg" title="{{ $row['ref'] }}">{{ $row['ref'] }}</code>
                @if ($row['digest'] !== null)
                    <code class="font-mono text-neutral-500 dark:text-fg-faint" title="{{ $row['digestFull'] }}">{{ $row['digest'] }}</code>
                    <x-copy-button :value="$row['digestFull']" label="Copy the digest of the running image" />
                @endif
            </span>
            @if ($row['known'] !== '')
                <span class="inline-flex items-center gap-1.5 whitespace-nowrap">
                    <span aria-hidden="true">·</span>
                    <button type="button" class="{{ $link }}" x-on:click="{{ $openFindings }}">{{ $row['known'] }}</button>
                </span>
            @endif
            <span class="inline-flex items-center gap-1.5 whitespace-nowrap">
                <span aria-hidden="true">·</span>
                <button type="button" class="{{ $link }}" x-on:click="{{ $openPackages }}">Packages and SBOM</button>
            </span>
            @if ($hasSettings)
                <span class="inline-flex items-center gap-1.5 whitespace-nowrap">
                    <span aria-hidden="true">·</span>
                    <button type="button" class="{{ $link }}"
                        x-on:click="$dispatch('open-settings-section', { id: 'image-scan-settings-section' })">Settings</button>
                </span>
            @endif
        </div>
    @endif
</div>
