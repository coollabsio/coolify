{{-- One action of the Security main card: its button (confirmation first) or its copy text. $withSentence adds the
     sentence for the other actions; the primary action's sentence is part of the image sentence. $keyPrefix (the
     image row) keeps keys unique when two images have the same action. --}}
@php
    $code = 'max-w-full truncate rounded-md border border-neutral-200 bg-neutral-50 px-2 py-1 font-mono text-[12px] text-neutral-800 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg';
    $button = $action['button'];
@endphp

<div wire:key="security-action-{{ ($keyPrefix ?? '') !== '' ? $keyPrefix.'-' : '' }}{{ $action['key'] }}"
    @class(['flex min-w-0 flex-col gap-2', 'sm:flex-row sm:items-center sm:justify-between sm:gap-4' => $withSentence])>
    @if ($withSentence)
        <p class="min-w-0 text-[13px] leading-5 text-neutral-800 dark:text-fg">{{ $action['sentence'] }}</p>
    @endif

    @if ($button !== null)
        <div class="shrink-0">
            <x-modal-confirmation :title="$button['title']" :buttonTitle="$button['label']" :submitAction="$button['submit']"
                :actions="$button['actions']" :warningMessage="e($button['warning'])" :confirmWithText="false"
                :confirmWithPassword="false" :step2ButtonText="$button['label']">
                <x-slot:trigger>
                    @if ($primary)
                        <x-forms.button isHighlighted class="normal-case!">{{ $button['label'] }}</x-forms.button>
                    @else
                        <button type="button" class="button normal-case!">{{ $button['label'] }}</button>
                    @endif
                </x-slot:trigger>
            </x-modal-confirmation>
        </div>
    @elseif (filled($action['copy']))
        <div class="flex min-w-0 shrink-0 items-center gap-1">
            <code class="{{ $code }}" title="{{ $action['copy'] }}">{{ $action['copy'] }}</code>
            <x-copy-button :value="$action['copy']" :label="'Copy '.$action['copy']" />
        </div>
    @endif
</div>
