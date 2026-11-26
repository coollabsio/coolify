{{--
    Masked server address with a reveal toggle and a copy action. The address is loaded from the
    server only on reveal or copy, so it is never in the page source.

    @param string $uuid JS expression that resolves to the server uuid
--}}
<div class="relative z-10 flex min-w-0 items-center gap-1" x-on:click.prevent.stop=""
    x-on:mousedown.stop="" x-on:keydown.enter.stop="">
    <span class="min-w-0 truncate font-mono text-[11px] text-neutral-500 dark:text-fg-dim"
        :class="{ 'tracking-[0.15em]': !isAddressRevealed({{ $uuid }}) }"
        :title="isAddressRevealed({{ $uuid }}) ? revealedAddresses[{{ $uuid }}] : null"
        x-text="isAddressRevealed({{ $uuid }}) ? (revealedAddresses[{{ $uuid }}] ?? '-') : '{{ str_repeat('•', 10) }}'"></span>
    <button type="button" x-on:click="toggleAddress({{ $uuid }})" class="icon-button size-6 shrink-0"
        :aria-pressed="isAddressRevealed({{ $uuid }}) ? 'true' : 'false'"
        :title="isAddressRevealed({{ $uuid }}) ? 'Hide address' : 'Show address'"
        :aria-label="isAddressRevealed({{ $uuid }}) ? 'Hide address' : 'Show address'">
        <x-reicon name="eye" x-show="!isAddressRevealed({{ $uuid }})" class="size-3.5" />
        <x-reicon name="eye-off2" x-cloak x-show="isAddressRevealed({{ $uuid }})" class="size-3.5" />
    </button>
    <x-copy-button :resolve="'serverAddress('.$uuid.')'" label="Copy address" class="size-6" />
</div>
