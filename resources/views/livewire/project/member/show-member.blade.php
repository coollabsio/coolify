<tr @class([
    'dark:text-white text-black dark:bg-coolblack dark:hover:bg-coolgray-100',
    'dark:bg-coolgray-100 bg-neutral-200' => $projectMember->user_id == Auth::id(),
])>
    <td class="px-5 py-4 text-sm whitespace-nowrap">
        {{ $projectMember->user->name }}
    </td>
    <td class="px-5 py-4 text-sm whitespace-nowrap">
        {{ $projectMember->user->email }}
    </td>
    <td class="px-5 py-4 text-sm whitespace-nowrap">
        {{ $projectMember->role->value }}
    </td>
    <td class="flex gap-2 px-5 py-4 text-sm whitespace-nowrap">
        @if (auth()->user()->isAdmin() || auth()->user()->isOwner() || $project->getProjectMember(auth()->user())?->canManage())
            @if ($projectMember->user_id !== Auth::id())
                @if ($projectMember->role->value !== 'viewer')
                    <x-forms.button wire:click="changeRole('viewer')">To Viewer</x-forms.button>
                @endif
                @if ($projectMember->role->value !== 'deployer')
                    <x-forms.button wire:click="changeRole('deployer')">To Deployer</x-forms.button>
                @endif
                @if ($projectMember->role->value !== 'manager')
                    <x-forms.button wire:click="changeRole('manager')">To Manager</x-forms.button>
                @endif
                <x-forms.button isError wire:click="remove">Remove</x-forms.button>
            @else
                <div>(This is you)</div>
            @endif
        @else
            @if ($projectMember->user_id === Auth::id())
                <div>(This is you)</div>
            @endif
        @endif
    </td>
</tr>
