<div>
    <form wire:submit.prevent='inviteByLink' class="flex items-center gap-2">
        <x-forms.input id="email" type="email" name="email" placeholder="Email" />
        <x-forms.button type="submit">Invite with link</x-forms.button>
    </form>
</div>
