{{--
    Masked server address: click the value to show or hide it, or copy it. The address is loaded
    from the server only on reveal or copy, so it is never in the page source.

    @param string $uuid JS expression that resolves to the server uuid
--}}
<div class="relative z-10 flex min-w-0 items-center gap-1" x-on:click.prevent.stop=""
    x-on:mousedown.stop="" x-on:keydown.enter.stop="">
    <button type="button" x-on:click="toggleAddress({{ $uuid }})"
        class="min-w-0 cursor-pointer truncate rounded font-mono text-[12px] leading-6 text-neutral-500 transition-colors hover:text-black dark:text-fg-dim dark:hover:text-fg"
        :class="{ 'tracking-[0.15em]': !isAddressRevealed({{ $uuid }}) }"
        :aria-pressed="isAddressRevealed({{ $uuid }}) ? 'true' : 'false'"
        :title="isAddressRevealed({{ $uuid }}) ? 'Hide ' + (revealedAddresses[{{ $uuid }}] ?? 'address') : 'Show address'"
        :aria-label="isAddressRevealed({{ $uuid }}) ? 'Hide address' : 'Show address'"
        x-text="isAddressRevealed({{ $uuid }}) ? (revealedAddresses[{{ $uuid }}] ?? '-') : '{{ str_repeat('•', 10) }}'"></button>
    <x-copy-button :resolve="'serverAddress('.$uuid.')'" label="Copy address" class="size-6" />
</div>
