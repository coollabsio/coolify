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
                            <td class="flex gap-2" x-data="checkProtocol">
                                <template x-if="isHttps">
                                    <x-forms.button x-on:click="copyToClipboard('{{ $invite->link }}')">Copy Invitation
                                        Link</x-forms.button>
                                </template>
                                <x-forms.input id="null" type="password" value="{{ $invite->link }}" />
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

@script
    <script>
        Alpine.data('checkProtocol', () => {
            return {
                isHttps: window.location.protocol === 'https:'
            }
        })
    </script>
@endscript
