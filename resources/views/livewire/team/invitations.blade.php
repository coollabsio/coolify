<div>
    @if ($invitations->count() > 0)
        <h4 class="pb-2">Pending Invitations</h4>
        <div class="overflow-x-auto">
            <table>
                <thead>
                <tr>
                    <th>Email</th>
                    <th>Via</th>
                    <th>Role</th>
                    <th>Invitation Link</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody x-data>
                @foreach ($invitations as $invite)
                    <tr>
                        <td>{{ $invite->email }}</td>
                        <td>{{ $invite->via }}</td>
                        <td>{{ $invite->role }}</td>
                        <td x-on:click="copyToClipboard('{{ $invite->link }}')">
                            <x-forms.button>Copy Invitation Link</x-forms.button>
                        </td>
                        <td>
                            <x-forms.button wire:click.prevent='deleteInvitation({{ $invite->id }})'>Revoke
                                Invitation
                            </x-forms.button>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

    @endif
</div>
