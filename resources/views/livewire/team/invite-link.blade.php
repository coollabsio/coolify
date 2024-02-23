<div>
    <form wire:submit='viaLink' class="flex items-center gap-2">
        <x-forms.input id="email" type="email" name="email" placeholder="Email" />
        <x-forms.select id="role" name="role">
            <option value="owner">Owner</option>
            <option value="admin">Admin</option>
            <option value="member">Member</option>
        </x-forms.select>
        <x-forms.button type="submit">Generate Invitation Link</x-forms.button>
        @if (is_transactional_emails_active())
            <x-forms.button wire:click.prevent='viaEmail'>Send Invitation Email</x-forms.button>
        @endif
    </form>
</div>
