<div>
    @foreach (auth()->user()->otherTeams() as $team)
        <x-inputs.button wire:key="{{ $team->id }}" wire:click="switch_to('{{ $team->id }}')">Switch to:
            {{ $team->name }}</x-inputs.button>
    @endforeach
</div>
