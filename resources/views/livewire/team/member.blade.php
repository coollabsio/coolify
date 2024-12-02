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
        {{ data_get($member, 'pivot.role') }}
    </td>
    <td class="flex gap-2 px-5 py-4 text-sm whitespace-nowrap">
        @if (Auth::user()->isAdminFromSession())
            @if ($member->id !== Auth::id())
                @if (Auth::user()->isOwner())
                    @if (data_get($member, 'pivot.role') === 'owner')
                        <x-forms.button wire:click="makeAdmin">To Admin</x-forms.button>
                        <x-forms.button wire:click="makeReadonly">To Member</x-forms.button>
                        <x-forms.button isError wire:click="remove">Remove</x-forms.button>
                    @endif
                    @if (data_get($member, 'pivot.role') === 'admin')
                        <x-forms.button wire:click="makeOwner">To Owner</x-forms.button>
                        <x-forms.button wire:click="makeReadonly">To Member</x-forms.button>
                        <x-forms.button isError wire:click="remove">Remove</x-forms.button>
                    @endif
                    @if (data_get($member, 'pivot.role') === 'member')
                        <x-forms.button wire:click="makeOwner">To Owner</x-forms.button>
                        <x-forms.button wire:click="makeAdmin">To Admin</x-forms.button>
                        <x-forms.button isError wire:click="remove">Remove</x-forms.button>
                    @endif
                @elseif (Auth::user()->isAdmin())
                    @if (data_get($member, 'pivot.role') === 'admin')
                        <x-forms.button wire:click="makeReadonly">To Member</x-forms.button>
                        <x-forms.button isError wire:click="remove">Remove</x-forms.button>
                    @endif
                    @if (data_get($member, 'pivot.role') === 'member')
                        <x-forms.button wire:click="makeAdmin">To Admin</x-forms.button>
                        <x-forms.button isError wire:click="remove">Remove</x-forms.button>
                    @endif
                @endif
            @else
                <div>(This is you)</div>
            @endif
        @endif
    </td>
</tr>
