<div>
    <x-forms.button wire:click='checkUpdate' type="submit">
        Check Update</x-forms.button>
    @if ($updateAvailable)
        Update available
    @endif
</div>
