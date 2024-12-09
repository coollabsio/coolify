<form wire:submit='viaLink' class="flex flex-col items-start gap-2 lg:items-end lg:flex-row">
    <div class="flex flex-1 gap-2">
        <x-forms.input id="email" type="email" label="Email" name="email" placeholder="Email" required />
        <x-forms.select id="role" name="role" label="Role">
            @if (auth()->user()->role() === 'owner')
                <option value="owner">Owner</option>
            @endif
            <option value="admin">Admin</option>
            <option value="member">Member</option>
        </x-forms.select>
    </div>
    <div class="flex gap-2">
        <x-forms.button type="submit">Generate Invitation Link</x-forms.button>
        @if (is_transactional_emails_enabled())
            <x-forms.button wire:click.prevent='viaEmail'>Send Invitation via Email</x-forms.button>
        @endif
    </div>
</form>
