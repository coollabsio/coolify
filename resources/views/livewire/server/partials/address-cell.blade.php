{{--
    Masked server address with a per-row reveal toggle and a copy action.

    @param string $uuid       JS expression resolving to the server uuid
    @param string $name       JS expression resolving to the server name
    @param string $valueClass Extra classes for the address value
--}}
@php
    $revealed = "isAddressRevealed($uuid)";
    $address = "serverAddress($uuid)";
@endphp

<span class="{{ $valueClass }} min-w-0 truncate font-mono"
    :class="{ 'tracking-[0.15em]': !{{ $revealed }} }"
    x-text="{{ $revealed }} ? ({{ $address }} ?? '-') : addressMask"></span>
<button type="button" x-on:click.prevent.stop="toggleAddress({{ $uuid }})" class="icon-button size-6"
    :aria-pressed="{{ $revealed }} ? 'true' : 'false'"
    :title="{{ $revealed }} ? 'Hide address' : 'Show address'"
    :aria-label="({{ $revealed }} ? 'Hide address of ' : 'Show address of ') + {{ $name }}">
    <x-reicon name="eye" x-show="!{{ $revealed }}" class="size-3.5" />
    <x-reicon name="eye-off2" x-cloak x-show="{{ $revealed }}" class="size-3.5" />
</button>
<x-copy-button :resolve="$address" label="Copy address" class="size-6"
    x-bind:aria-label="'Copy address of ' + {{ $name }}" />
