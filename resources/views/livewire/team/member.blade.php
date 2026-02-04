<tr @class([
    'dark:text-white text-black dark:bg-coolblack dark:hover:bg-coolgray-100',
    'dark:bg-coolgray-100 bg-neutral-200' => $member->id == Auth::id(),
])>
    <td class="px-5 py-4 text-sm whitespace-nowrap">
        {{ $member->name }}
    </td>
    <td class="px-5 py-4 text-sm whitespace-nowrap">
        {{ $member->email }}
    </td>
    <td class="px-5 py-4 text-sm whitespace-nowrap">
        {{ ucfirst(data_get($member, 'pivot.role')) }}
    </td>
    <td class="flex gap-2 px-5 py-4 text-sm whitespace-nowrap">
        @can('manageMembers', currentTeam())
            @if ($member->id !== Auth::id())
                @php
                    $memberRole = data_get($member, 'pivot.role');
                @endphp
                @if (Auth::user()->isOwner())
                    @if ($memberRole === 'owner')
                        <x-forms.button wire:click="makeAdmin">To Admin</x-forms.button>
                        <x-forms.button wire:click="makeMember">To Member</x-forms.button>
                        <x-forms.button wire:click="makeViewer">To Viewer</x-forms.button>
                        <x-forms.button isError wire:click="remove">Remove</x-forms.button>
                    @endif
                    @if ($memberRole === 'admin')
                        <x-forms.button wire:click="makeOwner">To Owner</x-forms.button>
                        <x-forms.button wire:click="makeMember">To Member</x-forms.button>
                        <x-forms.button wire:click="makeViewer">To Viewer</x-forms.button>
                        <x-forms.button isError wire:click="remove">Remove</x-forms.button>
                    @endif
                    @if ($memberRole === 'member')
                        <x-forms.button wire:click="makeOwner">To Owner</x-forms.button>
                        <x-forms.button wire:click="makeAdmin">To Admin</x-forms.button>
                        <x-forms.button wire:click="makeViewer">To Viewer</x-forms.button>
                        <x-forms.button isError wire:click="remove">Remove</x-forms.button>
                    @endif
                    @if ($memberRole === 'viewer')
                        <x-forms.button wire:click="makeOwner">To Owner</x-forms.button>
                        <x-forms.button wire:click="makeAdmin">To Admin</x-forms.button>
                        <x-forms.button wire:click="makeMember">To Member</x-forms.button>
                        <x-forms.button isError wire:click="remove">Remove</x-forms.button>
                    @endif
                @elseif (Auth::user()->isAdmin())
                    @if ($memberRole === 'admin')
                        <x-forms.button wire:click="makeMember">To Member</x-forms.button>
                        <x-forms.button wire:click="makeViewer">To Viewer</x-forms.button>
                        <x-forms.button isError wire:click="remove">Remove</x-forms.button>
                    @endif
                    @if ($memberRole === 'member')
                        <x-forms.button wire:click="makeAdmin">To Admin</x-forms.button>
                        <x-forms.button wire:click="makeViewer">To Viewer</x-forms.button>
                        <x-forms.button isError wire:click="remove">Remove</x-forms.button>
                    @endif
                    @if ($memberRole === 'viewer')
                        <x-forms.button wire:click="makeAdmin">To Admin</x-forms.button>
                        <x-forms.button wire:click="makeMember">To Member</x-forms.button>
                        <x-forms.button isError wire:click="remove">Remove</x-forms.button>
                    @endif
                @endif
            @else
                <div>(This is you)</div>
            @endif
        @else
            @if ($member->id === Auth::id())
                <div>(This is you)</div>
            @endif
        @endcan
    </td>
</tr>
