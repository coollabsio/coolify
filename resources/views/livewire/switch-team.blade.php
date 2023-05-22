<div class="pt-4">
    @if (auth()->user()->otherTeams()->count() > 0)
        <p>Switch to:</p>
        <div class="flex gap-2">
            @foreach (auth()->user()->otherTeams() as $team)
                <x-inputs.button isHighlighted wire:key="{{ $team->id }}"
                    wire:click="switch_to('{{ $team->id }}')">
                    {{ $team->name }}</x-inputs.button>
            @endforeach
        </div>
    @endif

</div>
