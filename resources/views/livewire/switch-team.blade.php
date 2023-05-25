<div class="pt-4">
    <h3>Switch Team</h3>
    @if (auth()->user()->otherTeams()->count() > 0)
        <div class="flex gap-2">
            @foreach (auth()->user()->otherTeams() as $team)
                <x-forms.button isHighlighted wire:key="{{ $team->id }}"
                    wire:click="switch_to('{{ $team->id }}')">
                    {{ $team->name }}</x-forms.button>
            @endforeach
        </div>
    @endif
</div>
