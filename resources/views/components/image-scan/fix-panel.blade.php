@props([
    'items' => [],
    'canUpdate' => false,
])

@php
    $linkClasses = 'inline-flex items-center gap-0.5 font-medium text-coollabs hover:underline dark:text-brand-light';
    $tile = 'mt-px flex size-6 shrink-0 items-center justify-center rounded-md bg-neutral-100 text-neutral-600 ring-1 ring-neutral-200 dark:bg-white/[0.06] dark:text-fg-dim dark:ring-white/[0.08]';
@endphp

@if ($items !== [])
    <div id="fix-panel" data-image-scan-fix-panel
        class="flex flex-col divide-y divide-neutral-200 border-b border-neutral-200 dark:divide-white/[0.06] dark:border-white/[0.08]">
        @foreach ($items as $item)
            @php $update = $item['update']; @endphp
            <div wire:key="fix-panel-{{ $item['key'] }}" class="flex flex-col gap-2 p-3">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex min-w-0 items-start gap-2.5">
                        <span class="{{ $tile }}" aria-hidden="true">
                            <x-reicon :name="$update !== null ? 'bandage' : 'info-circle'" class="size-3.5" />
                        </span>
                        <div class="flex min-w-0 flex-col gap-0.5">
                            <span class="truncate font-mono text-[11px] text-neutral-500 dark:text-fg-faint" title="{{ $item['image'] }}">{{ $item['image'] }}</span>
                            @if ($update !== null)
                                <p class="text-[12px] leading-5 text-neutral-800 dark:text-fg">{{ $update['text'] }}</p>
                            @elseif ($item['message'] !== null)
                                <p class="text-[12px] leading-5 text-neutral-700 dark:text-fg-dim">
                                    {{ $item['message'] }}
                                    @if ($item['registryUrl'] !== null)
                                        <a href="{{ $item['registryUrl'] }}" target="_blank" rel="noopener noreferrer" class="{{ $linkClasses }}">
                                            Image page <x-reicon name="external-link" class="size-3" />
                                        </a>
                                    @endif
                                    @if ($item['loginUrl'] !== null)
                                        <a href="{{ $item['loginUrl'] }}" {{ wireNavigate() }} class="{{ $linkClasses }}">Registry logins</a>
                                    @endif
                                </p>
                            @endif
                        </div>
                    </div>

                    @if ($update !== null && $canUpdate)
                        <div class="shrink-0" wire:key="fix-panel-action-{{ $item['key'] }}-{{ md5($update['ref']) }}">
                            <x-modal-confirmation :title="$update['title']" :buttonTitle="$update['button']"
                                :submitAction="'updateImage(\'' . $update['ref'] . '\', \'' . $item['owner'] . '\')'"
                                :actions="$update['actions']" :warningMessage="e($update['warning'])"
                                :confirmWithText="false" :confirmWithPassword="false" :step2ButtonText="$update['button']">
                                <x-slot:trigger>
                                    <button type="button" class="button normal-case!">{{ $update['button'] }}</button>
                                </x-slot:trigger>
                            </x-modal-confirmation>
                        </div>
                    @endif
                </div>

                @if ($item['major'] !== null)
                    <div x-data="{ open: false }" class="pl-8.5">
                        <button type="button" class="inline-flex items-center gap-1 text-[12px] font-medium text-neutral-600 hover:text-black dark:text-fg-dim dark:hover:text-fg"
                            x-on:click="open = !open" :aria-expanded="open ? 'true' : 'false'">
                            <x-reicon name="chevron-down" class="size-3.5 transition-transform duration-150" ::class="open ? 'rotate-0' : '-rotate-90'" />
                            Newer major version
                        </button>
                        <div x-show="open" x-cloak x-transition.opacity.duration.150ms class="mt-1.5">
                            <x-callout type="warning" title="{{ $item['major'] }} is available">
                                A new major version can change how the image works. Check the release notes before you change it.
                            </x-callout>
                        </div>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
@endif
