<tr class="border-coolgray-200">
    <th class="text-warning">{{ $member->id }}</th>
    <td>{{ $member->name }}</td>
    <td>{{ $member->email }}</td>
    <td>
        {{-- @if (auth()->user()->isAdmin())
            <x-forms.button wire:click="makeAdmin">Make admin</x-forms.button>
        @else
            <x-forms.button disabled>Make admin</x-forms.button>
        @endif --}}
        @if ($member->id !== auth()->user()->id)
            <x-forms.button wire:click="remove">Remove</x-forms.button>
        @else
            <x-forms.button disabled>Remove</x-forms.button>
        @endif
    </td>
</tr>
