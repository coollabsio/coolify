<tr class="border-coolgray-200">
    <th class="text-warning">{{ $member->id }}</th>
    <td>{{ $member->name }}</td>
    <td>{{ $member->email }}</td>
    <td>
        @if ($member->id !== auth()->user()->id)
            <x-forms.button class="border-none">Remove</x-forms.button>
        @endif
    </td>
</tr>
